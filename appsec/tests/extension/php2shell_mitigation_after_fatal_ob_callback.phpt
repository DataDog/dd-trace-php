--TEST--
PHP2Shell mitigation (CVE-2026-109618): after a deferred fatal the request stays armed, so a parse_str() in an output callback that crosses the limit again is deferred again, not the engine's error in the middle of an insertion
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless(70100);
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// As php2shell_mitigation_after_fatal_shutdown_parse_str.phpt, with the
// parse_str() in an output callback, which runs when output ends after the
// first deferred fatal. A fatal error inside an output callback cannot be
// displayed (output buffering is locked there), so the raises are counted
// from the appsec log. log_errors=1 so that the engine's own error inside
// the callback would reach stderr, where the native check looks.
require __DIR__ . '/inc/php2shell_cgi.php';
$r = p2s_cgi_run(['memory_limit' => '16M', 'max_input_vars' => '1000000', 'log_errors' => '1',
        'datadog.appsec.log_file' => 'stderr'], [],
    p2s_cgi_env(__DIR__ . '/inc/php2shell_child_after_fatal.php', 'GET', [
        'P2S_FATAL' => 'alloc', 'P2S_AFTER' => 'ob', 'P2S_ALLOC' => (string)(10 * P2S_MIB),
        'P2S_SRC' => (string)(6 * P2S_MIB),
    ]));
$raises = p2s_raises($r);
$native = p2s_native_oom($r);
$started = strpos($r['err'], 'PARSE usage=') !== false;
$parsed = strpos($r['err'], 'PARSED') !== false;
echo "parse_started=", var_export($started, true), " parse_ended=", var_export(!$parsed, true),
    " raises=$raises native=", var_export($native, true), " status={$r['status']}\n";
if (!$started || $parsed || $raises !== 2 || $native) {
    p2s_dump($r);
}
--EXPECT--
parse_started=true parse_ended=true raises=2 native=false status=255
