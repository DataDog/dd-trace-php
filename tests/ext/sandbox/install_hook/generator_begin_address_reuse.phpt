--TEST--
A generator replaced in its begin callback cannot land on the freed original's address
--DESCRIPTION--
The frame's record is filed under the generator object's address, and the creation path decides whether to publish it by comparing that address against whatever is in the return slot afterwards.
It captured the object without taking a reference, so a callback that dropped the last reference -- overrideReturnValue(null) plus clearing $returned -- freed it, and Zend's object store handed the very same slot to the next generator allocated.
The comparison then matched an unrelated generator and filed this function's record under it, whose destructor dereferenced its already-NULL execute_data and crashed.
Creation holds a reference across the callbacks, so the address stays unique for as long as the comparison depends on it.
--SKIPIF--
<?php
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

DDTrace\install_hook('gen', function ($h) {
    $originalId = spl_object_id($h->returned);
    $h->overrideReturnValue(null);
    $h->returned = null;
    $new = replacement();
    var_dump($originalId === spl_object_id($new));   // false: the original is still held
    $h->overrideReturnValue($new);
}, function () { echo "end\n"; });

$g = gen();
var_dump(iterator_to_array($g));
unset($g);
echo "Done.\n";
?>
--EXPECT--
bool(false)
end
array(1) {
  [0]=>
  int(2)
}
Done.
