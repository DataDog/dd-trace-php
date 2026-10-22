--TEST--
PHP2Shell mitigation (CVE-2026-109618): with the mitigation off or appsec explicitly disabled, the input_filter wrapper drops nothing and a request crossing memory_limit gets the engine's own error
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
p2s_skip_unless_cgi();
p2s_skip_if_no_zend_mm();
--INI--
extension=ddtrace.so
max_input_vars=10000000
post_max_size=64M
--FILE--
<?php
// The wrapper is installed unconditionally; at request time it drops only
// while a parse is armed, and nothing arms with the mode off or with appsec
// explicitly disabled (INI or environment). Both bodies then reach the
// engine's own out-of-memory error, with no deferred fatal and no mitigation
// log line, and a small body keeps every field. The many-fields body is the
// CVE path itself (a bailout in the middle of an insertion).
require __DIR__ . '/inc/php2shell_cgi.php';
const FIELDS = 240000;
$single = 'f' . str_repeat('Z', 4000000) . '[k]=1';
$many = '';
for ($i = 0; $i < FIELDS; $i++) {
    $many .= sprintf('f%07d=vvvvvvvv&', $i);
}
$bodies = [
    'single' => [$single, (int)round(0.4 * p2s_calibrate([], [], $single) / P2S_MIB)],
    'many' => [$many, (int)round(0.5 * p2s_calibrate([], [], $many) / P2S_MIB)],
];

$configs = [
    'mode_off' => [[], ['DD_APPSEC_PHP2SHELL_MITIGATION' => 'off']],
    'appsec_ini_0' => [['datadog.appsec.enabled' => '0'], []],
    'appsec_env_0' => [[], ['DD_APPSEC_ENABLED' => '0']],
];
foreach ($configs as $cname => list($ini, $env)) {
    foreach ($bodies as $bname => list($body, $soft_mib)) {
        $soft = $soft_mib * P2S_MIB;
        $r = p2s_cgi_run($ini + ['memory_limit' => "{$soft_mib}M"], [], $env, $body);
        $outcome = p2s_outcome($r, $soft);
        echo "$cname $bname: outcome=$outcome mitigation_log=",
            var_export(strpos($r['out'] . $r['err'], 'PHP2Shell mitigation:') !== false, true), "\n";
        if ($outcome !== 'native') {
            p2s_dump($r, "soft=$soft");
        }
    }
    $small = p2s_child_lines(p2s_cgi_run($ini, [], $env, 'a=1&b[]=2&c=x+y'));
    echo "$cname small: post_fields=", $small ? $small[0]['post_fields'] : 'none', "\n";
}
--EXPECT--
mode_off single: outcome=native mitigation_log=false
mode_off many: outcome=native mitigation_log=false
mode_off small: post_fields=3
appsec_ini_0 single: outcome=native mitigation_log=false
appsec_ini_0 many: outcome=native mitigation_log=false
appsec_ini_0 small: post_fields=3
appsec_env_0 single: outcome=native mitigation_log=false
appsec_env_0 many: outcome=native mitigation_log=false
appsec_env_0 small: post_fields=3
