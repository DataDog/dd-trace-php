<?php

require_once __DIR__."/includes/autoload.php";
skip_if_not_php8();
skip_if_opcache_missing();

if (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 0 && php_uname('m') === 'aarch64') {
    echo "Skip: JIT not working on PHP 8.0 on aarch64\n";
    exit(0);
}

$msg_disabled = "OPcache JIT is enabled and may cause instability. ddtrace will be disabled unless the environment DD_INJECT_FORCE is set to '1', 'true', 'yes' or 'on'";
$msg_forced = "OPcache JIT is enabled and may cause instability. Ignoring as DD_INJECT_FORCE is enabled";

$tests = [
    // OPcache disabled in CLI
    [
        "config" => "-dzend_extension=opcache -ddatadog.trace.cli_enabled=1",
        "must_not_contain" => [$msg_disabled],
        "must_contain" => [<<<EOT
ddtrace.disable: NO
OPcache is disabled
EOT
],
    ],
    // OPcache enabled, but not JIT
    [
        "config" => "-dzend_extension=opcache -dopcache.enable_cli=1 -ddatadog.trace.cli_enabled=1",
        "must_not_contain" => [$msg_disabled],
        "must_contain" => [<<<EOT
ddtrace.disable: NO
opcache_enabled: YES
jit.enabled: NO
jit.on: NO
jit.buffer_size: 0
EOT
],
    ],
    // JIT enabled
    [
        "config" => "-dzend_extension=opcache -dopcache.enable_cli=1 -ddatadog.trace.cli_enabled=1 -dopcache.jit_buffer_size=32M -dopcache.jit=tracing",
        "must_not_contain" => [],
        "must_contain" => [
            $msg_disabled,
            <<<EOT
ddtrace.disable: YES
opcache_enabled: YES
jit.enabled: YES
jit.on: YES
jit.buffer_size: 33554416
EOT
],
    ],
    // JIT enabled + force injection
    [
        "config" => "-dzend_extension=opcache -dopcache.enable_cli=1 -ddatadog.trace.cli_enabled=1 -dopcache.jit_buffer_size=32M -dopcache.jit=tracing",
        "env" => ['DD_INJECT_FORCE=1'],
        "must_not_contain" => [],
        "must_contain" => [
            $msg_forced,
            <<<EOT
ddtrace.disable: NO
opcache_enabled: YES
jit.enabled: YES
jit.on: YES
jit.buffer_size: 33554416
[ddtrace] [span] Encoding span
EOT
],
    ],
];
foreach ($tests as $data) {
    $env = ['DD_TRACE_DEBUG=1'];
    if (isset($data['env'])) {
        $env = array_merge($env, $data['env']);
    }
    $output = runCLI($data['config'].' '.__DIR__.'/fixtures/opcache_jit.php', true, $env);

    foreach ($data['must_contain'] as $str) {
        assertContains($output, $str);
    }
    foreach ($data['must_not_contain'] as $str) {
        assertNotContains($output, $str);
    }
}
