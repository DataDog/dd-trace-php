--TEST--
A generator begin callback that releases its own generator does not leave the walk standing in freed memory
--DESCRIPTION--
The frame record was published before the begin handlers so that a join could find it.
For an ordinary frame that is safe -- it is keyed by an execute_data that cannot go away mid-call.
A generator record is keyed by a user-destroyable object, so publishing it early let `$h->returned = null` run a destructor that finished and freed the record while zai_hook_continue() was still walking it; returning from the callback then read the freed dynamic payload and wrote `walking` back into the freed record.
Generator records are published after the walk instead, and a join refuses the creation frame outright so nothing needs to find one during it.
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
$first = DDTrace\install_hook('gen', function ($h) {
    echo "begin\n";
    $h->overrideReturnValue(replacement());
    $h->returned = null;
    echo "begin done\n";
}, function () { echo "end\n"; });
$g = gen();
var_dump(iterator_to_array($g));
unset($g);

// Churn the same shape, then confirm an ordinary generator at a recycled address still reports normally.
DDTrace\remove_hook($first);
$ends = 0;
DDTrace\install_hook('gen', null, function () use (&$ends) { $ends++; });
for ($i = 0; $i < 5; ++$i) { foreach (gen() as $v) {} }
echo "plain ends=$ends\n";
echo "done\n";
?>
--EXPECT--
begin
begin done
end
array(1) {
  [0]=>
  int(2)
}
plain ends=5
done
