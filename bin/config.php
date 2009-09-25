<?php
// This is going to require the timer class...
$pwd = dirname(__FILE__) . "/"; 
require_once($pwd . '/timer.php');

class dbsnap_config {
	private $settings = array(
		'win_mysqldump_command'=>'c:/xampp/mysql/bin/mysqldump.exe',
		'win_git_command'=>'"C:/Program Files/Git/bin/git.exe"',
		'mysqldump_command'=>'/usr/bin/mysqldump',
		'git_command'=>'/usr/local/bin/git',
		'git_command2'=>'/usr/bin/git'
		//'mysqldump_command'=>'mysqldump'
	);
	// This is the configuration file for the dbsnap utility.
	private $alias = array(
		'mini' => 'mysql.mini.me'
	);

	private $dbconfig = array(
		'mysql.mini.me'=>array(
			'driver' => 'mysql',
			'persistent' => false,
			'host' => 'mysql.mini.me',
			'login' => 'username',
			'password' => 'passwd',
			'database' => 'mydatabase',
			'prefix' => '',
			'filter'=> array(
				'mydatabase'=>array(
					'skip_this_table'=>1,
					'skip_this_table2'=>1
				),
			),
			'type'=> array(
				'mydatabase'=>array(
					'myfirstlog' => 'log',
					'mysecondlog' => 'log'
				),
				'another_database'=>array(
					'another_log_file' => 'log',
					'configuration' => 'once',
				)
			)
		)
	);
	
	/* DO NOT EDIT BELOW THIS LINE */
	
	private static $instance;

	private function __construct() { }
	
	public static function getInstance() {
		if (empty(self::$instance)) {
			self::$instance = new dbsnap_config();
		}
		return self::$instance;
	}

	public static function getSettings() {
		return self::getInstance()->settings;
	}
	
	public static function getAlias() {
		return self::getInstance()->alias;
	}

	public static function getDbConfig() {
		return self::getInstance()->dbconfig;
	}
	
	public static function setConfig($key, $val) {
		return self::getInstance()->config[$key] = $val;
	}
	
	public static function getConfig($key=null) {
		$i = self::getInstance();
		if (empty($key)) return $i->config;
		if (!empty($i->config[$key])) return $i->config[$key];
		return;
	}
	
}
?>
