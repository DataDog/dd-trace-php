--TEST--
PHP2Shell mitigation (CVE-2026-109618/109619): an explicitly disabled appsec turns the whole mitigation off, with no hook installed
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=0
memory_limit=128M
--CGI--
--FILE--
<?php
// Runs under php-cgi (--CGI--): the mitigation is off under the cli SAPI.
// datadog.appsec.enabled=0 is an explicit false, visible at startup, so no
// hook is installed and the mitigation is off for the process: on an
// unpatched runtime, with the default mode auto, it is inactive. It is
// appsec's state, not the mode, that disables it here. php2shell_mitigation_input_filter_off
// and _post_deactivate_hook check the behaviour behind this state.
require __DIR__ . '/inc/php2shell_cgi.php';
$minfo = p2s_minfo();
echo "mode=", $minfo[P2S_ROW_MODE], "\n";
echo "state=", $minfo[P2S_ROW_STATE], "\n";
// While inactive, MINFO does not report the hooks.
echo "treat_data row=", var_export(p2s_hook_row($minfo, 'treat_data') !== null, true), "\n";
--EXPECT--
mode=auto
state=Inactive
treat_data row=false
