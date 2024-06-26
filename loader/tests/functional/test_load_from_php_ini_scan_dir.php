<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$output = runCLI('-v', false, [
    'TEST_MODULE_PATH='.__DIR__.'/../../modules',
    'PHP_INI_SCAN_DIR=:'.__DIR__.'/fixtures/ini',
], false);
assertContains($output, 'with ddtrace v');
assertContains($output, 'with dd_library_loader v');
