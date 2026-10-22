--TEST--
PHP2Shell mitigation (CVE-2026-109618): an expanding filter.default is logged once per process while the mitigation is active
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_ext('filter');
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
--FILE--
<?php
// The hard limit has no allowance for a filter.default that can expand
// values (see Coverage: filter.default, php2shell_mitigation.c). When a window
// arms with such a value, one warning is logged for the process. Each case
// serves two GET requests in one php-cgi process (-T 2); each request's
// startup GET parse arms a window. The mode is forced on, so the cases also
// run on a patched runtime:
//  - special_chars, mode on: one warning across both requests;
//  - unsafe_raw, mode on: none;
//  - special_chars, mode off: none (nothing is armed).
require __DIR__ . '/inc/php2shell_cgi.php';
$env = p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => 'a=%3Cb%3E']);

foreach ([['special_chars', 'on'], ['unsafe_raw', 'on'], ['special_chars', 'off']] as list($filter, $mode)) {
    $r = p2s_cgi_run(['filter.default' => $filter, 'datadog.appsec.log_file' => 'stderr'], ['-T', '2'],
        $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode]);
    $requests = count(p2s_child_lines($r));
    $warnings = preg_match_all('/PHP2Shell mitigation: filter\.default is set to a filter that can expand/',
        $r['out'] . $r['err']);
    echo "$filter mode=$mode: requests=$requests warnings=$warnings\n";
    if ($requests !== 2) {
        p2s_dump($r, "$filter mode=$mode");
    }
}
--EXPECT--
special_chars mode=on: requests=2 warnings=1
unsafe_raw mode=on: requests=2 warnings=0
special_chars mode=off: requests=2 warnings=0
