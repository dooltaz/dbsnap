<?php
// Global Classes


class Timer {

	public static $clicks;
	private static $instance;
	
	private function __construct() {}
	
	public static function getInstance() {
		if (empty(self::$instance)) {
			self::$instance = new Timer();
		}
		return self::$instance;
	}
	
	public static function write($clicks) {
		return self::getInstance()->clicks = $clicks;
	}
	
	public static function read() {
		return self::getInstance()->clicks;
	}
	
	public static function click($action="click") {
		$clicks = self::getInstance()->read();
		$now = Timer::_microtime_float();
		$clicks[] = array('name'=>$action, 'time'=>$now);
		$now = Timer::write($clicks);
		return $now;
	}
	
	public static function _microtime_float() {
		list($usec, $sec) = explode(" ", microtime());
		return ((float)$usec + (float)$sec);
	}
	
	public static function getReport() {
		$clicks = self::getInstance()->read();
		if (count($clicks)>1) {
			foreach($clicks as $i => $click) {
				if ($i == 0) {
					$first = $click;
					$last = $click;
				} else {
					$time_from_start = sprintf("%.2f Seconds", ($click['time'] - $first['time']));
					$time_from_last = sprintf("%.2f Seconds", ($click['time'] - $last['time']));
					$clicks[$i] = array_merge($click, array(
						'time_from_start'=>$time_from_start,
						'time_from_last'=>$time_from_last));
					$last = $click;
				}
			}
			return $clicks;
		} else {
			return $clicks;
		}
	}
}

Timer::click('start');

?>