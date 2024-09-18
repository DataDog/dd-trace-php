<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

if (!getenv("XDEBUG_SO_NAME")) {
    echo "Skip: test requires XDEBUG_SO_NAME env var (i.e. XDEBUG_SO_NAME=xdebug-3.3.0.so)\n";
    exit(0);
}

$msg_disabled = "Potentially incompatible extension detected: Xdebug. ddtrace will be disabled unless the environment DD_INJECT_FORCE is set to '1', 'true', 'yes' or 'on'";
$msg_forced = "Potentially incompatible extension detected: Xdebug. Ignoring as DD_INJECT_FORCE is enabled";

$output = runCLI('-dzend_extension='.getenv("XDEBUG_SO_NAME").' -v', true, ['DD_TRACE_DEBUG=1']);
assertContains($output, 'Found extension file');
assertContains($output, $msg_disabled);
assertNotContains($output, $msg_forced);
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with Xdebug v');
assertContains($output, 'with ddtrace v');

$output = runCLI('-dzend_extension='.getenv("XDEBUG_SO_NAME").' -v', true, ['DD_TRACE_DEBUG=1', 'DD_INJECT_FORCE=1']);
assertContains($output, 'Found extension file');
assertNotContains($output, $msg_disabled);
assertContains($output, $msg_forced);
assertContains($output, 'with dd_library_loader v');
assertContains($output, 'with Xdebug v');
assertContains($output, 'with ddtrace v');
