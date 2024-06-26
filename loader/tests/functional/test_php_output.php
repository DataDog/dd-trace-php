<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-v', false);
assertNotContains($output, 'ddtrace');
assertNotContains($output, 'dd_library_loader');

$output = runCLI('-v', true);
assertContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');

$output = runCLI('-m', false);
assertNotContains($output, 'ddtrace');
assertNotContains($output, 'dd_library_loader');

$output = runCLI('-m', true);
assertContains($output, 'ddtrace');
assertContains($output, 'dd_library_loader');
