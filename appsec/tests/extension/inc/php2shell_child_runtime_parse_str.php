<?php
// Child script for php2shell_mitigation_runtime_arming.phpt,
// php2shell_mitigation_runtime_parse_str.phpt and
// php2shell_mitigation_runtime_php70.phpt: parse_str() of a string with one
// large key after RINIT (built in place, so that building it peaks well below
// what parsing it needs), followed by an explicit VM safe point. While the
// request is armed, a crossing of memory_limit during parse_str() only marks
// the out-of-memory error pending and sets EG(vm_interrupt); the error is
// raised at the next VM interrupt. PHP 7.1-8.3 do not check for an interrupt
// after an internal call, so the script calls a user function and runs a loop
// (user function entry and loop back-edges are checked on every version from
// 7.1). PARSED is printed only if no error was raised by then. P2S_ML, if set,
// is applied with ini_set() first.
if (getenv('P2S_ML') !== false && getenv('P2S_ML') !== '') {
    ini_set('memory_limit', getenv('P2S_ML'));
}
function p2s_safe_point() {}
$s = str_repeat('Z', 4000000);
$s .= '[k]=1';
$base = memory_get_usage(true);
parse_str($s, $vars);
p2s_safe_point();
for ($i = 0; $i < 2; $i++) {}
echo "PARSED fields=", count($vars), " base=", $base,
    " peak=", memory_get_peak_usage(true), "\n";
