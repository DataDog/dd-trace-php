<?php
function ddtrace_version_at_least($version) {
	$ddtrace_version = phpversion('ddtrace');
	if (version_compare($version, preg_replace('/-.*$/', '', $ddtrace_version)) > 0) {
		die("skip for ddtrace >= $version; got $ddtrace_version");
	}
}
