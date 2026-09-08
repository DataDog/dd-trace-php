<?php
function get_filename() {
	return ini_get('datadog.appsec.log_file');
}
function log_contents() {
	return file_get_contents(get_filename());
}
function match_log() {
	$regexes = func_get_args();
	foreach ($regexes as $r) {
		$message_in_log = preg_match($r, log_contents()) === 1;
		if ($message_in_log) {
			echo "found message in log matching $r\n";
			return;
		}
	}
	echo "None of " . var_export(func_get_args(), true) . " have matched\n";
	echo "Log contents were:\n", log_contents();
}
function no_match_log() {
	$regexes = func_get_args();
	foreach ($regexes as $r) {
		if (preg_match($r, log_contents()) === 1) {
			echo "unexpected message in log matching $r\n";
			return;
		}
	}
	echo "no message in log matching " . implode(', ', $regexes) . "\n";
}
function truncate_log() {
	$f = fopen(get_filename(), 'c');
	ftruncate($f, 0);
}
