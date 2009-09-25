<?php
if(!defined('SNAP_ROOT')) define("SNAP_ROOT", dirname(dirname(__FILE__)));
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once(dirname(__FILE__) . '/config.php');
require_once(dirname(__FILE__) . '/db.php');

class dbsnap {

	private $db;
	
	public $settings;
	public $dbconfig;
	public $alias;
	public $os;
	public $out_dir;
	public $first_recovery = 1;
	
	// For windows only
	public $win_batchfile;
	
	function __construct() {
		$this->alias = dbsnap_config::getAlias();
		$this->dbconfig = dbsnap_config::getDbConfig();
		$this->settings = dbsnap_config::getSettings();
		$this->os = getenv('OS');
		$this->win_batchfile = SNAP_ROOT.DS.'tmp'.DS.'backup.bat';
		$this->out_dir = SNAP_ROOT.DS.'out';
		$fp = fopen($this->win_batchfile,'w');
		fwrite($fp, "\r\n");
		fclose($fp);
	}
	
	function db_connected() {
		return (!empty($this->db));
	}

	// Basic Snap Commands
	function snapServer($server) {
		if (!dbsnap_config::getConfig('q')) {
			print "Snapping - $server\n";
		}
		Timer::click('start: snapServer ' . $server);
		extract($this->findAlias($server));
		$user = $this->dbconfig[$server]['login'];
		$pass = $this->dbconfig[$server]['password'];

		$this->db = new db($server, $user, $pass);
		$sql = 'show databases;';
		$res = $this->db->querySelect($sql);
		if ($res['rows']) {
			foreach($res['data'] as $i=>$row) {
				$r = array_values($row);
				$filter_this = (!empty($this->dbconfig[$server]['filter'][$r[0]])) ?
					$this->dbconfig[$server]['filter'][$r[0]] : 0;
				if ($filter_this != 1) {
					$this->snapDb($server, $r[0]);
				} else {
					if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
						print "Skipping Database: {$r[0]}\n";
					}
				}
			}
		}
		unset($this->db);
		Timer::click('stop:  snapServer ' . $server);
		if (!dbsnap_config::getConfig('q')) {
			print "Snapped - $server\n";
		}
	}

	function snapDb($server, $db) {
		if (!dbsnap_config::getConfig('q')) {
			print "Snapping - $server | $db\n";
		}
		Timer::click('start: snapDb ' . $db);
		extract($this->findAlias($server, $db));
		$clean_connection = false;
		if(!$this->db_connected()) {
			$user = $this->dbconfig[$server]['login'];
			$pass = $this->dbconfig[$server]['password'];
			$this->db = new db($server, $user, $pass, $db);
			$clean_connection = true;
		}
		$this->db->selectDatabase($db);
		$sql = 'show tables;';
		$res = $this->db->querySelect($sql);
		if ($res['rows']) {
			foreach($res['data'] as $i=>$row) {
				$r = array_values($row);
				$filter_this = (!empty($this->dbconfig[$server]['filter'][$db][$r[0]])) ?
					$this->dbconfig[$server]['filter'][$db][$r[0]] : 0;
				if ($filter_this != 1) {
					$this->snapTable($server, $db, $r[0]);
				} else {
					if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
						print "Skipping Table: {$r[0]}\n";
					}
				}
			}
		}		
		if($clean_connection) {
			unset($this->db);
		}
		Timer::click('stop:  snapDb ' . $db);
		if (!dbsnap_config::getConfig('q')) {
			print "Snapped - $server | $db\n";
		}
	}

	
	function snapTable($server, $db, $table) {
		if (!dbsnap_config::getConfig('q')) {
			print "Snapping - $server | $db | $table\n";
		}
		Timer::click('start: snapTable ' . $table);
		extract($this->findAlias($server, $db, $table));
		$clean_connection = false;
		if(!$this->db_connected()) {
			$user = $this->dbconfig[$server]['login'];
			$pass = $this->dbconfig[$server]['password'];
			$this->db = new db($server, $user, $pass, $db);
			$clean_connection = true;
		}
		$this->db->selectDatabase($db);

		// Determine the table type
		$table_type = 'default';
		$db_type = (!empty($this->dbconfig[$server]['type'][$db])) ?
			$this->dbconfig[$server]['type'][$db] : 'default';
		if (is_array($db_type)) $db_type = 'default';
		$tj = $this->dbconfig[$server]['type'][$db];
		$table_type = (is_array($tj) && !empty($tj[$table])) ? 
			$this->dbconfig[$server]['type'][$db][$table] : $db_type;
			
		if ($table_type == 'once') {
			if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
				print "Table $server | $db | $table Set to type='once'\n";
			}
			$fname = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table.DS.$table.".sql";
			if (file_exists($fname)) {
				if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
					print "Table $server | $db | $table Already Snapped.\n";
				}
				return;
			}
			
		}
		
		// Build information for this table
		$fields = array();
		$pk = null;
		$pkmin = null;
		$pkmax = null;
		$cnt = null;
		$table_md5 = '';
		
		$sql = 'describe ' . $table;
		$res = $this->db->querySelect($sql);
		if($res['rows']) {
			$pk_cnt = 0;
			foreach($res['data'] as $i => $row) {
				$table_md5 .= $row['field'] . $row['type'] . $row['null'] . 
								$row['key'] . $row['default'] . $row['extra'];
				if ($row['key'] == 'PRI') {
					$pk = $row['field'];
					$pk_cnt++;
				}

				if ($row['null'] == 'YES') {
					$fields[] = "if(isnull({$row['field']}), '', {$row['field']})";
				} else {
					$fields[] = $row['field'];
				}
			}
		}
		// print $table_md5 . "\n";
		$table_md5 = md5($table_md5);
		$field_sql = implode(',', $fields);
		
		// IF This table has 1 primary key field, back it up.
		if (!empty($pk) && $pk_cnt == 1) {
			$sql = "SELECT max({$pk}) maxid, min({$pk}) minid, count(*) as cnt FROM {$table}";
			$res = $this->db->querySelect($sql);
			if($res['rows']) {
				$pkmin = $res['data'][0]['minid'];
				$pkmax = $res['data'][0]['maxid'];
				$cnt = $res['data'][0]['cnt'];
			}
			$start = (floor($pkmin/1000)) ? (floor($pkmin/1000) * 1000) : 0 ;
			$end = (ceil($pkmax/1000)) ? (ceil($pkmax/1000) * 1000) : 500 ;
			
			if ($table_type == 'log') {
				if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
					print "Table $server | $db | $table Set to type='log'\n";
				}
				$current_pos = $this->getLastRecord($server, $db, $table);
				$start = $this->getNewStart($start, $current_pos);
				if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
					print "New Start Position: $start\n";
				}
			}
			
			// Get the unique MD5 for this set of 500
			$current = $start;
			
			// print "\n$field_sql\n";exit;
			// print "TODO: Need to double check id's for \$i to be sure nothing is skipped\n\n";
			for($i=$start;$i<=$end;$i+=500) {
				$first_id = $i;
				$last_id = $i + 500;
				
				$sql = "select
					md5(concat(GROUP_CONCAT(i.j))) as unique_md5
					FROM (SELECT
					floor(({$last_id} - o.{$pk}) / 25) as grp, count(*) as cnt,
					md5(concat(GROUP_CONCAT(MD5(CONCAT(
					{$field_sql}
					))))) as j
					FROM {$table} o
					where o.{$pk} > {$first_id} and o.{$pk} <= {$last_id}
					group by grp
					order by {$pk} asc) as i";
				$res = $this->db->querySelect($sql);
				if (!empty($res['data'][0]['unique_md5'])) {
					$md5 = $res['data'][0]['unique_md5'];
					$oldmd5 = $this->getDataMd5($server, $db, $table, $last_id);
					
					if ($md5 != $oldmd5) {
						if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
							print "Found Update: $table FROM $first_id to $last_id\n";
						}
						if (!dbsnap_config::getConfig('d')) {
							$where_sql = "{$pk}>{$first_id} and {$pk}<={$last_id}";
							$this->snapDataSql($server, $db, $table, $where_sql, $last_id);
							if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
								print "Creating New MD5: $md5\n";
							}
							$this->setDataMd5($md5, $server, $db, $table, $last_id);
						}
					}
				}
			}
		} else {
			// Backup the table that has multiple PK's or no PK
			$sql = "SELECT count(*) as cnt FROM {$table}";
			$res = $this->db->querySelect($sql);
			if($res['rows']) {
				$cnt = $res['data'][0]['cnt'];
			}
			$start = 0 ;
			$end = ($cnt) ? $cnt : 500 ;
			
			if ($table_type == 'log') {
				if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
					print "Table $server | $db | $table Set to type='log'\n";
				}
				$current_pos = $this->getLastRecord($server, $db, $table);
				$start = $this->getNewStart($start, $current_pos);
				if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
					print "New Start Position: $start\n";
				}
			}
			
			for($i=$start;$i<=$end;$i+=500) {
				$first_id = $i;
				$last_id = $i + 500;
				$sql = "select MD5(CONCAT(
					{$field_sql}
					)) as m5
					FROM {$table} o
					order by {$field_sql} asc
					limit {$first_id}, 500
					";
				$res = $this->db->querySelect($sql);
				$md5sum = '';
				if($res['rows']) {
					foreach($res['data'] as $m => $row) {
						$md5sum .= $row['m5'];
					}
				}
				$md5 = md5($md5sum);
				unset($md5sum);
				$oldmd5 = $this->getDataMd5($server, $db, $table, $last_id);
				if ($md5 != $oldmd5) {
					if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
						print "Found Update: $table FROM $first_id to $last_id\n";
					}

					if (!dbsnap_config::getConfig('d')) {
						$where_sql = "true limit {$first_id}, 500";
						$this->snapDataSql($server, $db, $table, $where_sql, $last_id);
						if (dbsnap_config::getConfig('v') && !dbsnap_config::getConfig('q')) {
							print "Creating New MD5: $md5\n";
						}
						$this->setDataMd5($md5, $server, $db, $table, $last_id);
					}
				}
			}
		}
		
		$current_table_md5 = $this->getTableMd5($server, $db, $table);
		if ($table_md5 != $current_table_md5) {
			$this->snapTableSql($server, $db, $table);
			$this->setTableMd5($table_md5, $server, $db, $table);
		}
		
		
		if($clean_connection) {
			unset($this->db);
		}
		Timer::click('stop:  snapTable ' . $table);
		if (!dbsnap_config::getConfig('q')) {
			print "Snapped - $server | $db | $table\n";
		}
	}

	function recoverServer($server, $type='server') {
		if (!dbsnap_config::getConfig('q')) {
			print "Recovering - $server\n";
		}
		if (in_array(dbsnap_config::getConfig('type'), array('db','server','table'))) $type = dbsnap_config::getConfig('type');
		if ($type == 'server') $this->first_recovery = 1;
		extract($this->findAlias($server));

		$dir = SNAP_ROOT.DS.'current'.DS.$server;
		if (is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if (!preg_match("/^\./", $file)) {
						$this->recoverDb($server, $file, $type);
					}
				}
				closedir($dh);
			}
		}
		if ($type == 'server') $this->appendFile($type, $server); 
		if (!dbsnap_config::getConfig('q')) {
			print "Recovered - $server\n";
		}
	}

	function recoverDb($server, $db, $type='db') {
		if (!dbsnap_config::getConfig('q')) {
			print "Recovering - $server | $db\n";
		}
		if (in_array(dbsnap_config::getConfig('type'), array('db','server','table'))) $type = dbsnap_config::getConfig('type');
		if ($type == 'db') $this->first_recovery = 1;
		extract($this->findAlias($server, $db));

		$dir = SNAP_ROOT.DS.'current'.DS.$server.DS.$db;
		if (is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if (!preg_match("/^\./", $file)) {
						$this->recoverTable($server, $db, $file, $type);
					}
				}
				closedir($dh);
			}
		}
		if ($type == 'db') $this->appendFile($type, $server, $db); 
		if (!dbsnap_config::getConfig('q')) {
			print "Recovered - $server | $db\n";
		}
	}
	
	function recoverTable($server, $db, $table, $type='table') {
		if (!dbsnap_config::getConfig('q')) {
			print "Recovering - $server | $db | $table\n";
		}
		if (in_array(dbsnap_config::getConfig('type'), array('db','server','table'))) $type = dbsnap_config::getConfig('type');
		if ($type == 'table') $this->first_recovery = 1;
		extract($this->findAlias($server, $db, $table));

		$dir = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table;
		if (file_exists($dir.DS.$table.'.sql')) {
			$this->recoverCopy($dir.DS.$table.'.sql', $server, $db, $table, $type);
		}
		$table_dirs = $this->getFileDirs($server, $db, $table);
		
		if (is_array($table_dirs) && count($table_dirs)>0) {
			foreach($table_dirs as $key => $file) {
				$this->recoverTableFile($server, $db, $table, $file, $type);
			}
		}
		if ($type == 'table') $this->appendFile($type, $server, $db, $table); 
		if (!dbsnap_config::getConfig('q')) {
			print "Recovered - $server | $db | $table\n";
		}
	}

	function recoverTableFile($server, $db, $table, $tablefile, $type='table') {
		extract($this->findAlias($server, $db, $table));

		$dir = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table.DS.$tablefile;
		
		$files = $this->getFiles($server, $db, $table, $tablefile);
		if (is_array($files) && count($files)>0) {
			foreach($files as $key => $file) {
				$this->recoverCopy($dir.DS.$file, $server, $db, $table, $type);
			}
		}
	}

	function appendFile($type, $server, $db='', $table='', $content="") {
		$content = "\n\nSET foreign_key_checks=1;\nSET unique_checks=1;\n" . $content; 
                switch($type) {
                        case "server":
                                $outfile = $this->out_dir.DS.$server.'.sql';
                        break;
                        case "db":
                                $outfile = $this->out_dir.DS.$server.'.'.$db.'.sql';
                        break;
                        case "table":
                                $outfile = $this->out_dir.DS.$server.'.'.$db.'.'.$table.'.sql';
                        break;
                }
		if (file_exists($this->out_dir)) {
			$fp = fopen($outfile, "a");
			fwrite($fp, $content . "\n");
			fclose($fp);
		}
	}

	function recoverCopy($from_file, $server, $db, $table, $type, $last=0) {
		$content = file_get_contents($from_file);
		$fstatus = 'a';
		$outfile = '';
		$overwrite = 0;
		if ($this->first_recovery == 1) {
			$overwrite=1;
			$this->first_recovery = 0;
		}
		
		switch($type) {
			case "server":
				$outfile = $this->out_dir.DS.$server.'.sql';
			break;
			case "db":
				$outfile = $this->out_dir.DS.$server.'.'.$db.'.sql';
			break;
			case "table":
				$outfile = $this->out_dir.DS.$server.'.'.$db.'.'.$table.'.sql';
			break;
		}
		if (file_exists($this->out_dir)) {
			$prefix = "";
			if ($overwrite) {
				$fstatus = 'w';
				$content = "SET foreign_key_checks=0;\nSET unique_checks=0;\n\n" . $content;
			}
			$content = preg_replace("/\/\*.*\*\/;\n/", "", $content);
			$content = preg_replace("/LOCK TABLES .* WRITE;\n/", "", $content);
			$content = preg_replace("/UNLOCK TABLES;\n/", "", $content);

			$fp = fopen($outfile, $fstatus);
			fwrite($fp, $content . "\n");
			fclose($fp);
		}
	}


	function getLastRecord($server, $db, $table) {
		$file_dirs = array_values($this->getFileDirs($server, $db, $table));
		$last_file_dir = $file_dirs[count($file_dirs)-1];
		$files = array_keys($this->getFiles($server, $db, $table, $last_file_dir));
		$last_record = $files[count($files)-1];
		return $last_record;
	}
	
	function getFiles($server, $db, $table, $file_dir) {
		extract($this->findAlias($server, $db, $table));
		$dir = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table.DS.$file_dir;
		$files = array();
		if (is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if (!preg_match("/^\./", $file) && (preg_match("/\./", $file))>0) {
						if (file_exists($dir.DS.$file)) {
							$index = preg_replace("/$table/", '', $file);
							$index = preg_replace("/\.sql/", '', $index);
							$index = ($index + 0);
							$files[$index] = $file;
						}
					}
				}
				closedir($dh);
			}
		}
		ksort($files);
		return $files;
	}
	
	function getFileDirs($server, $db, $table) {
		extract($this->findAlias($server, $db, $table));
		$dir = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table;
		$file_dirs = array();
		if (is_dir($dir)) {
			if ($dh = opendir($dir)) {
				while (($file = readdir($dh)) !== false) {
					if (!preg_match("/^\./", $file) && (preg_match("/\./", $file))>0) {
						if (is_dir($dir.DS.$file)) {
							$index = preg_replace("/$table\./", '', $file);
							$index = ($index + 0);
							$file_dirs[$index] = $file;
						}
					}
				}
				closedir($dh);
			}
		}
		ksort($file_dirs);
		return $file_dirs;
	}
	
	function hasChangedTable($server, $db, $table) {
		Timer::click('start: hasChangedTable ' . $table);
		extract($this->findAlias($server, $db, $table));
		$clean_connection = false;
		if(!$this->db_connected()) {
			$user = $this->dbconfig[$server]['login'];
			$pass = $this->dbconfig[$server]['password'];
			$this->db = new db($server, $user, $pass, $db);
			$clean_connection = true;
		}
		$this->db->selectDatabase($db);

		$table_md5 = '';
		
		$sql = 'describe ' . $table;
		$res = $this->db->querySelect($sql);
		if($res['rows']) {
			foreach($res['data'] as $i => $row) {
				$table_md5 .= $row['field'] . $row['type'] . $row['null'] . 
					$row['key'] . $row['default'] . $row['extra'];
			}
		}
		$table_md5 = md5($table_md5);
		$current_table_md5 = $this->getTableMd5($server, $db, $table);
		return ($table_md5 != $current_table_md5);
	}
	
	
	
	// Snap Table Support Functions
	function snapTableSql($server, $db, $table) {
		extract($this->findAlias($server, $db, $table));

		if (empty($this->settings['mysqldump_command'])) {
			die("Please be sure MySQL Dump is configured properly");
		}
		
		$grp = $this->findGroup($last_id);
		$filepath = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table;
		$filename = $filepath.DS.$table.".sql";
		$this->createPath($filepath);
		
		$options = array(
			'--skip-extended-insert',
			'--skip-comments',
			'--no-data',
			'--no-create-db',
			'--result-file=' . "\"" . $filename . "\"",
			'-h ' . $server,
			'-u ' . $this->dbconfig[$server]['login'],
			'-p' . $this->dbconfig[$server]['password']
		);
		$this->snapDump($db, $table, $options);
		return true;
	}

	function snapDataSql($server, $db, $table, $where, $last_id) {
		extract($this->findAlias($server, $db, $table));
		
		if (empty($this->settings['mysqldump_command'])) {
			die("Please be sure MySQL Dump is configured properly");
		}
		$grp = $this->findGroup($last_id);
		$filepath = SNAP_ROOT.DS.'current'.DS.$server.DS.$db.DS.$table.DS.$table.'.'.$grp;
		$filename = $filepath.DS.$table.$last_id.".sql";
		$this->createPath($filepath);
		
		$options = array(
			'--skip-extended-insert',
			'--skip-comments',
			'--no-create-db',
			'--no-create-info',
			"--where=\"{$where}\"",
			'--result-file=' . "\"" . $filename . "\"",
			'-h ' . $server,
			'-u ' . $this->dbconfig[$server]['login'],
			'-p' . $this->dbconfig[$server]['password']
		);
		$this->snapDump($db, $table, $options);
		return true;
	}

	function snapDump($db, $table, $options) {
			if (strtoupper(substr($this->os, 0, 3)) === 'WIN') {
				$command = $this->settings['win_mysqldump_command'] . " " .
					implode(' ', $options) . " " . 
					$db . " " . $table;
				$fp = fopen($this->win_batchfile, 'a');
				fwrite($fp, $command . "\r\n");
				fclose($fp);
				$res = true;
			} else {
				$command = $this->settings['mysqldump_command'] . " " .
					implode(' ', $options) . " " . 
					$db . " " . $table;
				$res = system($command);
			}
			return true;
	}
	
	
	// MD5 read/write functions //
	function getTableMd5($server, $db, $table) {
		$current_path = SNAP_ROOT . DS . 'current';
		$md5_path = $current_path.DS.$server.DS.$db.DS.$table.DS.".snap";
		return $this->getMd5($md5_path .DS. $table . ".md5");
	}
	
	function getDataMd5($server, $db, $table, $last_id) {
		$current_path = SNAP_ROOT . DS . 'current';
		$grp = $this->findGroup($last_id);
		$md5_path = $current_path.DS.$server.DS.$db.DS.$table.DS.$table.'.'.$grp.DS.".snap";
		return $this->getMd5($md5_path .DS. $last_id . ".md5");
	}

	function getMd5($file) {
		if (file_exists($file)) {
			return trim(file_get_contents($file));
		}
		return false;
	}
	
	function setTableMd5($md5, $server, $db, $table) {
		$current_path = SNAP_ROOT . DS . 'current';
		$md5_path = $current_path.DS.$server.DS.$db.DS.$table.DS.".snap";
		$this->createPath($md5_path);
		return $this->setMd5($md5, $md5_path .DS. $table . ".md5");
	}
	
	function setDataMd5($md5, $server, $db, $table, $last_id) {
		$current_path = SNAP_ROOT . DS . 'current';
		$grp = $this->findGroup($last_id);
		$md5_path = $current_path.DS.$server.DS.$db.DS.$table.DS.$table.'.'.$grp.DS.".snap";
		$this->createPath($md5_path);
		return $this->setMd5($md5, $md5_path .DS. $last_id . ".md5");
	}
	
	function setMd5($md5, $file) {
		$fp = fopen($file, "w");
		fwrite($fp, $md5);
		fclose($fp);
		return true;
	}
	

	// Support Functions
	// Recursively create an entire path if it doesn not exist.
	function createPath($path) {
		if (file_exists($path) && is_dir($path)) {
			return 1;
		}
		$ret = true;
		$elements = explode(DS, $path);
		array_pop($elements);
		$new_path = implode(DS, $elements);
		if (!file_exists($new_path)) {
			$ret = $this->createPath($new_path);
		}
		
		if ($ret) return mkdir($path, 0777);
		else return $ret;
	}

	function findGroup($last) {
		return floor($last/50000)+1;
	}
	
	function findAlias($server=null, $db=null, $table=null) {
		$this_array = array('server'=>$server,'db'=>$db,'table'=>$table);
		if (!empty($this->alias[$server])) $this_array['server'] = $this->alias[$server];
		if (!empty($this->alias[$db])) $this_array['db'] = $this->alias[$db];
		if (!empty($this->alias[$table])) $this_array['table'] = $this->alias[$table];
		return $this_array;
	}
	
	function getNewStart($start, $end, $rollback=5000) {
		if ($end <= $rollback) return $start;
		return ((floor($end/1000)*1000)-$rollback);
	}

}

?>
