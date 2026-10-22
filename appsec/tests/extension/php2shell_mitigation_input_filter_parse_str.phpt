--TEST--
PHP2Shell mitigation (CVE-2026-109618): the input_filter wrapper passes ext/filter's PARSE_STRING rewrite through to parse_str(), and forwards sapi_getenv()'s NULL new_val_len
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_ext('filter');
p2s_skip_if_patched();
p2s_skip_unless_cgi();
--INI--
extension=ddtrace.so
error_reporting=E_ALL & ~E_DEPRECATED
--FILE--
<?php
// For PARSE_STRING, ext/filter applies filter.default by replacing *val and
// *new_val_len and returning 1; parse_str() then registers the replaced value.
// sapi_getenv() calls the filter with PARSE_STRING and a NULL new_val_len, and
// returns the replaced value. Both run after RINIT, outside any armed window,
// so the wrapper only forwards: the results are the same with the mitigation
// on and off, and the NULL new_val_len must not be written through.
require __DIR__ . '/inc/php2shell_cgi.php';
$env = p2s_cgi_env(__DIR__ . '/inc/php2shell_child_parse_str_filter.php', 'GET',
    ['P2S_ENV' => 'e<1>']);

function result($r) {
    return preg_match('/^RESULT .*$/m', $r['out'], $m) ? $m[0] : "NO OUTPUT: {$r['out']}{$r['err']}";
}

$filters = [
    'no_default' => [],
    'special_chars' => ['filter.default' => 'special_chars'],
];
foreach ($filters as $fname => $ini) {
    $on = result(p2s_cgi_run($ini, [], $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'on']));
    $off = result(p2s_cgi_run($ini, [], $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'off']));
    echo "$fname: $on\n";
    echo "$fname on_eq_off=", var_export($on === $off, true), "\n";
    if ($on !== $off) {
        echo "$fname off: $off\n";
    }
}
--EXPECT--
no_default: RESULT [{"a":"<b>","b":["1"],"c":"x y"},"e<1>"]
no_default on_eq_off=true
special_chars: RESULT [{"a":"&#60;b&#62;","b":["1"],"c":"x y"},"e&#60;1&#62;"]
special_chars on_eq_off=true
