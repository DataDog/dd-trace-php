<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_php5();

$output = runCLI('-v', false);
assertNotContains($output, 'ddtrace');
assertNotContains($output, 'dd_library_loader');

$output = runCLI('-v', true);
assertNotContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');
