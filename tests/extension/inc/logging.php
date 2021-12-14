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
			echo "found message in log matching $r";
			return;
		}
	}
	echo "Log contents were:\n", log_contents();
}
function truncate_log() {
	$f = fopen(get_filename(), 'c');
	ftruncate($f, 0);
}
