--TEST--
PHP2Shell mitigation (CVE-2026-109618): the resolved state, the hook rows and the hard limit are reported
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
memory_limit=128M
post_max_size=8M
--POST--
a=1
--FILE--
<?php
// MINFO carries the state; status() keeps only the hard limit (checked
// against the formula in php2shell_mitigation_hard_limit_formula.phpt) and
// the post_deactivate counter.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "state=", $minfo[P2S_ROW_STATE], "\n";
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "treat_data=", p2s_hook_row($minfo, 'treat_data'), "\n";
echo "input_filter=", p2s_hook_row($minfo, 'input_filter'), "\n";
echo "deactivate=", p2s_hook_row($minfo, 'deactivate'), "\n";
// request_parse_body() exists only on PHP 8.4+; the row is absent before.
$rpb = p2s_hook_row($minfo, 'request_parse_body');
echo "rpb_ok=", var_export(PHP_VERSION_ID >= 80400 ? $rpb === 'Installed' : $rpb === null, true), "\n";

$s = datadog\appsec\testing\php2shell_mitigation_status();
ksort($s);
echo "status_keys=", implode(',', array_keys($s)), "\n";
echo "hard_above_soft=", var_export($s['hard_limit'] > 128 * P2S_MIB, true), "\n";
echo "post=", json_encode($_POST), "\n";
--EXPECT--
state=Active
mode=auto
treat_data=Installed
input_filter=Installed
deactivate=Installed
rpb_ok=true
status_keys=hard_limit,post_deactivate_resets
hard_above_soft=true
post={"a":"1"}
