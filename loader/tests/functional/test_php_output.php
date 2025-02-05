<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-v', false);
assertNotContains($output, 'ddtrace');
assertNotContains($output, 'dd_library_loader');
assertNotContains($output, 'datadog-profiling');
assertNotContains($output, 'ddappsec');

$output = runCLI('-v', true);
assertContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with datadog-profiling v');
assertContains($output, 'with ddappsec v');

$output = runCLI('-m', false);
assertNotContains($output, 'ddtrace');
assertNotContains($output, 'dd_library_loader');
assertNotContains($output, 'datadog-profiling');
assertNotContains($output, 'ddappsec');

$output = runCLI('-m', true);
assertContains($output, 'ddtrace');
assertContains($output, 'dd_library_loader');
assertContains($output, 'datadog-profiling');
assertContains($output, 'ddappsec');
