<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-v', true, ['DD_TRACE_DEBUG=1']);
assertContains($output, 'Found extension file');
assertContains($output, 'Extension \'ddtrace\' is not loaded');

preg_match('/Found extension file: ([^\n]*)/', $output, $matches);
$ext = $matches[1];
$tmp = tempnam(sys_get_temp_dir(), 'test_loader_');
copy($ext, $tmp);

$output = runCLI('-dextension='.$tmp.' -v', true, ['DD_TRACE_DEBUG=1']);
assertContains($output, 'Found extension file');
assertContains($output, 'Extension \'ddtrace\' is already loaded, unregister the injected extension');
assertContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');
