<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

if (!getenv("XDEBUG_SO_NAME")) {
    echo "Skip: test requires XDEBUG_SO_NAME env var (i.e. XDEBUG_SO_NAME=xdebug-3.3.0.so)\n";
    exit(0);
}

$output = runCLI('-dzend_extension='.getenv("XDEBUG_SO_NAME").' -v', true, ['DD_TRACE_DEBUG=1']);
assertContains($output, 'Found extension file');
assertContains($output, 'Incompatible extension \'Xdebug\' detected, unregister the injected extension');
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with Xdebug v');
assertNotContains($output, 'with ddtrace v');
