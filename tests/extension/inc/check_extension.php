<?php
function check_extension($name) {
	if (extension_loaded($name)) {
		return 'loaded';
	}
	if (file_exists(ini_get('extension_dir') . "/$name.so")) {
		return 'unloaded';
	}
	die("skip extension $name unavailable");
}
