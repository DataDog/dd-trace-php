--TEST--
Report backtrace into a pre-existing or shared _dd.stack attribute
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_APPSEC_MAX_STACK_TRACES=0
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

use function datadog\appsec\testing\report_exploit_backtrace;

function ids($root) {
    return array_column($root->attributes['_dd.stack']['exploit'], 'id');
}

DDTrace\start_span();
$root = DDTrace\active_span();

echo "-- null _dd.stack is replaced\n";
$root->attributes['_dd.stack'] = null;
var_dump(report_exploit_backtrace("a"));
var_dump(ids($root));

echo "-- copies of the attributes are not modified\n";
$copy = $root->attributes;
var_dump(report_exploit_backtrace("b"));
var_dump(ids($root), array_column($copy['_dd.stack']['exploit'], 'id'));

echo "-- missing exploit is added, other members are kept\n";
$root->attributes['_dd.stack'] = ['other' => 1];
var_dump(report_exploit_backtrace("c"));
var_dump(array_keys($root->attributes['_dd.stack']), ids($root));

echo "-- a non-array _dd.stack is left alone\n";
$root->attributes['_dd.stack'] = 'str';
var_dump(report_exploit_backtrace("d"));
var_dump($root->attributes['_dd.stack']);

DDTrace\close_span(0);
?>
--EXPECTF--
-- null _dd.stack is replaced
bool(true)
array(1) {
  [0]=>
  string(1) "a"
}
-- copies of the attributes are not modified
bool(true)
array(2) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
}
array(1) {
  [0]=>
  string(1) "a"
}
-- missing exploit is added, other members are kept
bool(true)
array(2) {
  [0]=>
  string(5) "other"
  [1]=>
  string(7) "exploit"
}
array(1) {
  [0]=>
  string(1) "c"
}
-- a non-array _dd.stack is left alone

Warning: datadog\appsec\testing\report_exploit_backtrace(): [ddappsec] Field '_dd.stack' is of type '6', expected 'array' in %s on line %d
bool(false)
string(3) "str"
