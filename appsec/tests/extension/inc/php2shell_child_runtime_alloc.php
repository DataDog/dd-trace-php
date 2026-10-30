<?php
// Child script for php2shell_mitigation_runtime_hard_limit.phpt,
// php2shell_mitigation_runtime_ini_set.phpt,
// php2shell_mitigation_lifecycle_fallbacks.phpt and
// php2shell_mitigation_sapi_off.phpt (and p2s_runtime_kind() of
// php2shell_cgi.php): allocations after RINIT, each followed
// by an explicit VM safe point (see php2shell_child_runtime_parse_str.php for
// why both a user function call and a loop). Each step is driven by an
// environment variable and skipped when it is unset or empty:
//   P2S_STUB_MM         call p2s_stub_mm_install() (p2s_stub.so) first and
//                       print STUB_MM installed=true|false
//   P2S_ML              ini_set('memory_limit') first
//   P2S_REQUEST_STATUS  after printing BASE (which allocates, so a changed
//                       memory_limit has been seen), print REQUEST_STATUS
//                       armed=true|false hard=N from
//                       php2shell_mitigation_request_status()
//   P2S_ALLOC           one allocation of that many bytes, kept to the end
//   P2S_ALLOC_TEMP      then one allocation of that many bytes, freed at once
//   P2S_USER_ERROR      then trigger_error(E_USER_ERROR) after SCRIPT RAN
//   P2S_SHUTDOWN_ALLOC  a shutdown function allocates that many bytes
//   P2S_SHUTDOWN_PARSE_STR
//                       then, in the same shutdown function, a string with
//                       one 4 MB key is built, usage is filled up to that
//                       many bytes and the string is parsed with parse_str()
// The shutdown function ends with a safe point and prints SHUTDOWN RAN only
// if no error was raised by then. Prints BASE usage=N memory_limit=V before
// allocating, and SCRIPT RAN only if no error was raised by the last safe
// point.
function p2s_env($name) {
    $v = getenv($name);
    return $v === false || $v === '' ? null : $v;
}
function p2s_safe_point() {}

if (p2s_env('P2S_STUB_MM') !== null) {
    echo "STUB_MM installed=", var_export(p2s_stub_mm_install(), true), "\n";
}
if (p2s_env('P2S_ML') !== null) {
    ini_set('memory_limit', p2s_env('P2S_ML'));
}
if (p2s_env('P2S_SHUTDOWN_ALLOC') !== null || p2s_env('P2S_SHUTDOWN_PARSE_STR') !== null) {
    register_shutdown_function(function () {
        $t = p2s_env('P2S_SHUTDOWN_ALLOC') !== null
            ? str_repeat('s', (int)p2s_env('P2S_SHUTDOWN_ALLOC')) : '';
        $vars = [];
        $f = '';
        if (p2s_env('P2S_SHUTDOWN_PARSE_STR') !== null) {
            $k = str_repeat('Z', 4000000);
            $k .= '[k]=1';
            $fill = (int)p2s_env('P2S_SHUTDOWN_PARSE_STR') - memory_get_usage(true);
            $f = $fill > 0 ? str_repeat('f', $fill) : '';
            parse_str($k, $vars);
        }
        p2s_safe_point();
        for ($i = 0; $i < 2; $i++) {}
        // $f is printed so that opcache's dead code elimination (opcache is
        // always on in php-cgi from PHP 8.5) cannot free the filler early.
        echo "SHUTDOWN RAN len=", strlen($t), " fill=", strlen($f),
            " fields=", count($vars), "\n";
    });
}
echo "BASE usage=", memory_get_usage(true), " memory_limit=", ini_get('memory_limit'), "\n";
if (p2s_env('P2S_REQUEST_STATUS') !== null) {
    $st = datadog\appsec\testing\php2shell_mitigation_request_status();
    echo "REQUEST_STATUS armed=", var_export($st['armed'], true), " hard=", $st['hard_limit'], "\n";
}
$s = p2s_env('P2S_ALLOC') !== null ? str_repeat('x', (int)p2s_env('P2S_ALLOC')) : '';
if (p2s_env('P2S_ALLOC_TEMP') !== null) {
    str_repeat('t', (int)p2s_env('P2S_ALLOC_TEMP'));
}
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "SCRIPT RAN len=", strlen($s), "\n";
if (p2s_env('P2S_USER_ERROR') !== null) {
    trigger_error('P2S user error', E_USER_ERROR);
}
