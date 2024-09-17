<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_php8();
skip_if_opcache_missing();

$jit_disabled = [
    "-dzend_extension=opcache",
    "-dzend_extension=opcache -dopcache.jit_buffer_size=32M",
    "-dzend_extension=opcache -dopcache.jit_buffer_size=32M -dopcache.enable=0 -dopcache.enable_cli=1",
    "-dzend_extension=opcache -dopcache.jit=tracing -dopcache.enable_cli=1",
    "-dopcache.jit_buffer_size=32M -dopcache.jit=tracing -dopcache.enable=1 -dopcache.enable_cli=1",

];
foreach ($jit_disabled as $options) {
    $output = runCLI($options.' -v', true, ['DD_TRACE_DEBUG=1']);
    assertContains($output, 'Application instrumentation bootstrapping complete (\'ddtrace\')');
    assertContains($output, 'with dd_library_loader v');
    assertContains($output, 'with ddtrace v');
}

$jit_enabled = [
    "-dzend_extension=opcache -dopcache.jit_buffer_size=32M -dopcache.enable_cli=1",
];
foreach ($jit_enabled as $options) {
    $output = runCLI($options.' -v', true, ['DD_TRACE_DEBUG=1']);
    assertContains($output, 'Opcache JIT is enabled, unregister the injected extension');
    assertContains($output, 'with dd_library_loader v');
    assertNotContains($output, 'with ddtrace v');
}
