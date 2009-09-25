<?php
if(!defined('SNAP_ROOT')) define("SNAP_ROOT", dirname(dirname(__FILE__)));
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once(dirname(__FILE__) . '/config.php');

class gitsnap {

	public $settings;
	public $alias;
	public $os;
	public $project;
	public $current_pwd;
	
	// For windows only
	public $win_batchfile;
	
	function __construct() {
		$this->alias = dbsnap_config::getAlias();
		$this->settings = dbsnap_config::getSettings();
		$this->win_batchfile = SNAP_ROOT.DS.'tmp'.DS.'backup.bat';
		$this->os = getenv('OS');
		$this->project = SNAP_ROOT.DS.'current';
		$this->current_pwd = getcwd();
		$this->gitdir = $this->project . DS . '.git';
	}
	
	function gitRun($command) {
		if (strtoupper(substr($this->os, 0, 3)) === 'WIN') {
			$project = preg_replace("/\\\\/", '/', $this->gitdir);
			//$project = $this->gitdir;
			$cmd = $this->settings['win_git_command'] . " --git-dir=" . $project . " " . $command;
		} else {
			if (file_exists($this->settings['git_command'])) {
				$cmd = $this->settings['git_command'] . " --git-dir=" . $this->gitdir . " " . $command;
			} elseif (file_exists($this->settings['git_command2'])) {
				$cmd = $this->settings['git_command2'] . " --git-dir=" . $this->gitdir . " " . $command;
			} else return false;
		}
		return $this->system($cmd);
	}
	
	function system($command) {
		if (strtoupper(substr($this->os, 0, 3)) === 'WIN') {
			//$command = preg_replace("/\\\\/", '/', $command);
			$this->winSystem("cd " . $this->project);
			$this->winSystem($command);
		} else {
//			system($command);
			$res = `(cd {$this->project}; $command;)`;
		}
		return true;
	}
	
	function winSystem($command) {
		$fp = fopen($this->win_batchfile, 'a');
		fwrite($fp, $command . "\r\n");
		fclose($fp);
		return true;
	}
	
	function setProject($path) {
		return $this->project = $path;
	}
		
	function snap() {
		Timer::click('start: gitSnap');
		$this->gitAdd();
		$this->gitCommit();
		Timer::click('stop:  gitSnap');
	}




	function gitRm($file) {
		return $this->gitRun('rm '.$file);
	}
	
	function gitAdd($file = '.') {
		return $this->gitRun('add '.$file);
	}

	function gitCommit($message='Auto Commit', $file=null) {
		return $this->gitRun('commit -a -m "'.$message.'" '.$file);
	}
	
	function gitInit() {
		if (!file_exists($this->gitdir)) {
			$this->gitRun('init');
		}
	}
	
}

?>
