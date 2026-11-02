--TEST--
PHP2Shell mitigation (CVE-2026-109618): mbstring.encoding_translation on is logged once per process while the mitigation is active
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_unless_cgi();
p2s_skip_unless_cgi_ext('mbstring');
p2s_skip_if_no_zend_mm();
--FILE--
<?php
// The hard limit has no allowance for mbstring's encoding translation (see
// Coverage: mbstring.encoding_translation, php2shell_mitigation.c). When a
// window arms with it on, one warning is logged for the process. As in
// php2shell_mitigation_filter_default_warning.phpt, each case serves two GET
// requests in one php-cgi process (-T 2), with the mode forced:
//  - on, mode on: one warning across both requests;
//  - off, mode on: none;
//  - on, mode off: none (nothing is armed).
require __DIR__ . '/inc/php2shell_cgi.php';
$env = p2s_cgi_env(P2S_CHILD_SCRIPT, 'GET', ['QUERY_STRING' => 'a=b']);

foreach ([['1', 'on'], ['0', 'on'], ['1', 'off']] as list($translation, $mode)) {
    $r = p2s_cgi_run(['mbstring.encoding_translation' => $translation, 'datadog.appsec.log_file' => 'stderr'],
        ['-T', '2'], $env + ['DD_APPSEC_PHP2SHELL_MITIGATION' => $mode]);
    $requests = count(p2s_child_lines($r));
    $warnings = preg_match_all('/PHP2Shell mitigation: mbstring\.encoding_translation is on/',
        $r['out'] . $r['err']);
    echo "translation=$translation mode=$mode: requests=$requests warnings=$warnings\n";
    if ($requests !== 2) {
        p2s_dump($r, "translation=$translation mode=$mode");
    }
}
--EXPECT--
translation=1 mode=on: requests=2 warnings=1
translation=0 mode=on: requests=2 warnings=0
translation=1 mode=off: requests=2 warnings=0
