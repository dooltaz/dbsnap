<?php
$pwd = dirname(__FILE__) . "/"; 
if(!defined('SNAP_ROOT')) define("SNAP_ROOT", dirname(dirname(__FILE__)));
if(!defined('DS')) define('DS', DIRECTORY_SEPARATOR);
require_once($pwd . '/config.php');
require_once($pwd . '/db.php');
Timer::click('start: SNAP');

$repo = SNAP_ROOT.DS."current";

require_once($pwd . 'gitsnap.php');
$gitsnap = new gitsnap();

require_once($pwd . 'dbsnap.php');
$dbsnap = new dbsnap();

$gitsnap->gitInit();

// Get the ARGV input here and decide what to do

if (is_array($_SERVER['argv'])) {
	
	$argvs = explode(',', implode(',', $_SERVER['argv']));
}
array_shift($argvs); // Remove the command from the argvs
$cmd = array_shift($argvs);
$options = array();

while(preg_match("/^-.*/", $argvs[0])) {
	$option = array_shift($argvs);
	$value = 0;
	if (stristr($option, "=")) {
		list($option, $value) = explode("=", $option);
	} else {
		$value = 1;
	}
	$option = preg_replace("/-/", '', $option);
	$options[$option] = $value;
	dbsnap_config::setConfig($option, $value);
}

$j = dbsnap_config::getConfig();

list($server, $db, $table) = explode(',', implode(',', $argvs));
if (dbsnap_config::getConfig('g')) {
	$gitsnap->snap();
	exit;
}
switch($cmd) {
	case "snap":
		switch(1) {
			case (!empty($server) && !empty($db) && !empty($table)):
				$dbsnap->snapTable($server, $db, $table);
			break;
			case (!empty($server) && !empty($db)): 
				$dbsnap->snapDb($server, $db);
			break;
			case (!empty($server)):
				$dbsnap->snapServer($server);
			break;
			default:
				usage();exit;
			break;
		}
		if (dbsnap_config::getConfig('b') || dbsnap_config::getConfig('d')) {
		} else {
			$gitsnap->snap();
		}
	break;
	case "recover":
		switch(1) {
			case (!empty($server) && !empty($db) && !empty($table)):
				$dbsnap->recoverTable($server, $db, $table);
			break;
			case (!empty($server) && !empty($db)): 
				$dbsnap->recoverDb($server, $db);
			break;
			case (!empty($server)):
				$dbsnap->recoverServer($server);
			break;
			default:
				usage();exit;
			break;
		}
	break;
	default:
		usage();exit;
	break;
}


$gitsnap->system('cd ' . $gitsnap->current_pwd);
function usage() {
	print "\nUSAGE: snap.php <snap|recover> [options] <server> [<db>] [<table>]\n";
	print "----------------------------------------------------------\n";
	print "SNAP Options:\n";
	print "\t-v\tVerbose Output\n";
	print "\t-q\tQuiet, No Output\n";
	print "\t-d\tDry Run - Does not store data.\n";
	print "\t-g\tGit Commit Only\n";
	print "\t-b\tBackup Data Only\n";
	print "\nRECOVER Options:\n";
	print "\t-v\tVerbose Output\n";
	print "\t-q\tQuiet, No Output\n";
	print "\t-type=<type>\tType of recovery output (server, db, table)\n";
	exit;
}

Timer::click('stop:  SNAP');
$timer_info = print_r(Timer::getReport(), 1);
$fp = fopen(SNAP_ROOT.DS.'tmp'.DS.'run.log','a');
fwrite($fp, $timer_info);
fwrite($fp, "-------------------------\n");
fclose($fp);

?>
