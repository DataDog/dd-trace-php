--TEST--
Invalid root span parentId assignment does not decref the RHS value
--DESCRIPTION--
Assigning an invalid string to DDTrace\RootSpanData::$parentId normalizes the
property to the empty string. The write handler must not dtor the caller-owned
RHS zval while doing so: if the RHS aliases another span property, such as the
root span service, releasing it here can underflow the zend_string refcount.

That underflow may leave a poisoned service zval on the root span. A later child
span can then crash while inheriting span properties and addref'ing that stale
service value.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

function refcount_of($value): int {
    ob_start();
    debug_zval_dump($value);
    $dump = ob_get_clean();

    if (!preg_match('/refcount\((\d+)\)/', $dump, $matches)) {
        echo $dump;
        return -1;
    }

    return (int) $matches[1];
}

$root = \DDTrace\root_span();
$root->service = str_repeat("service.", 16);
$service = $root->service;

$before = refcount_of($service);

// Invalid parentId values are normalized to the empty string, but writing the
// property must not release the caller-owned RHS zval.
$root->parentId = $service;

$after = refcount_of($service);

var_dump($after === $before);
var_dump($root->parentId);
var_dump($root->service === $service);

?>
--EXPECT--
bool(true)
string(0) ""
bool(true)
