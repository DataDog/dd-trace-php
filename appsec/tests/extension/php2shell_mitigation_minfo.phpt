--TEST--
PHP2Shell mitigation: MINFO reports the state, the mode, the patched runtime, the WAF decision and one row per hook
--SKIPIF--
<?php
require __DIR__ . '/inc/php2shell_cgi.php';
p2s_skip_if_patched();
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
memory_limit=128M
--CGI--
--FILE--
<?php
// The headline is Active or Inactive only; the per-hook rows say whether each
// slot holds our wrapper (Installed), was captured but now holds something
// else (Displaced, see php2shell_mitigation_hook_displaced_after_startup.phpt)
// or was never captured (Not installed). phpinfo() prints the same rows.
// Runs under php-cgi (--CGI--), where phpinfo() prints HTML: the mitigation
// is off under the cli SAPI.
require __DIR__ . '/inc/php2shell_cgi.php';
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();
$rows = array_filter(p2s_info_rows($info), function ($k) {
    return strpos($k, 'PHP2Shell ') === 0;
}, ARRAY_FILTER_USE_KEY);
echo "same_as_reflection=", var_export($rows == array_filter(p2s_minfo(), function ($k) {
    return strpos($k, 'PHP2Shell') === 0;
}, ARRAY_FILTER_USE_KEY), true), "\n";
// The WAF decision row: pending until the first answer from the helper (this
// test has none).
foreach ([P2S_ROW_STATE, P2S_ROW_MODE, P2S_ROW_PATCHED, P2S_ROW_DECISION] as $name) {
    echo "$name => ", isset($rows[$name]) ? $rows[$name] : 'absent', "\n";
}
// zend_error_cb: the wrapper that detects native memory errors under the WAF
// decision monitor, installed with the other hooks.
foreach (['treat_data', 'input_filter', 'rfc1867', 'deactivate', 'zend_error_cb'] as $hook) {
    echo "$hook hook => ", var_export(p2s_hook_row($rows, $hook), true), "\n";
}
// php-cgi has a read_post (the SAPIs with a NULL one, whose slot is not
// captured, are those under which the mitigation is off).
echo "read_post hook => ", var_export(p2s_hook_row($rows, 'read_post'), true), "\n";
$rpb = p2s_hook_row($rows, 'request_parse_body');
echo "rpb_row_ok=", var_export(PHP_VERSION_ID >= 80400 ? $rpb === 'Installed' : $rpb === null, true), "\n";
// The interrupt and gc_mem_caches() hooks are installed whenever the others
// are: PHP 7.0 has no zend_interrupt_function, hence no row for either; 8.4+
// needs no gc_mem_caches() wrapper (zend_mm_gc() forwards to the gc handler).
// There is no memory_limit hook: the allocation handlers follow PG(memory_limit).
echo "memory_limit_row_absent=", var_export(p2s_hook_row($rows, 'memory_limit') === null, true), "\n";
$int = p2s_hook_row($rows, 'zend_interrupt_function');
echo "interrupt_row_ok=", var_export(PHP_VERSION_ID >= 70100 ? $int === 'Installed' : $int === null, true), "\n";
$gc = p2s_hook_row($rows, 'gc_mem_caches');
$gc_wrapped = PHP_VERSION_ID >= 70100 && PHP_VERSION_ID < 80400;
echo "gc_mem_caches_row_ok=", var_export($gc_wrapped ? $gc === 'Installed' : $gc === null, true), "\n";
// One row per hook (rpb on 8.4+, the interrupt on 7.1+, gc_mem_caches() on
// 7.1-8.3), plus the four above.
$hooks = 6 + (PHP_VERSION_ID >= 80400 ? 1 : 0) + (PHP_VERSION_ID >= 70100 ? 1 : 0) + ($gc_wrapped ? 1 : 0);
echo "row_count_ok=", var_export(count($rows) === 4 + $hooks, true), "\n";
--EXPECT--
same_as_reflection=true
PHP2Shell mitigation (CVE-2026-109618/109619) => Active
PHP2Shell mitigation mode => auto
PHP2Shell patched runtime => No
PHP2Shell WAF decision => pending
treat_data hook => 'Installed'
input_filter hook => 'Installed'
rfc1867 hook => 'Installed'
deactivate hook => 'Installed'
zend_error_cb hook => 'Installed'
read_post hook => 'Installed'
rpb_row_ok=true
memory_limit_row_absent=true
interrupt_row_ok=true
gc_mem_caches_row_ok=true
row_count_ok=true
