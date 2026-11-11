--TEST--
Overriding a generator's return value from its begin callback does not file a record under the wrong object
--DESCRIPTION--
A generator's record is keyed by the generator object, and the creation path captured that object before running the begin handlers.
Deriving the key from EX(return_value) afterwards instead read whatever overrideReturnValue() had put there, so a join filed the original function's record under the *replacement* -- whose destructor then dereferenced its already-NULL execute_data and crashed.
Publishing under the original was no better once the callback had dropped the last reference to it: nothing would ever finish that record, and the next generator allocated at the same address inherited it.
The record is published only for the object actually being returned, and closed out immediately when there is none.
--SKIPIF--
<?php
// Replacing a generator's return value from a begin hook is PHP 8+ only: dd_uhook_begin() copies EX(return_value) into HookData::$returned under #if PHP_VERSION_ID >= 80000 (uhook.c).
// On PHP 7 the override is ignored, so the scenario this covers cannot arise.
if (PHP_VERSION_ID < 80000) die('skip: generator return-value replacement requires PHP 8');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.hook_limit=0
--FILE--
<?php
function gen() { yield 1; }
function replacement() { yield 2; }
$late = null;
$early = DDTrace\install_hook('gen', function ($h) use (&$late) {
    echo "begin\n";
    $h->overrideReturnValue(replacement());
    $late = DDTrace\install_hook('gen', null, function ($h) { echo "late end\n"; });
    echo "begin done\n";
});
$g = gen();
echo "created\n";
var_dump(iterator_to_array($g));
unset($g);
DDTrace\remove_hook($early);
DDTrace\remove_hook($late);
gc_collect_cycles();
echo "done\n";
?>
--EXPECT--
begin
begin done
late end
created
array(1) {
  [0]=>
  int(2)
}
done
