--TEST--
DD_TRACE_SECURE_RANDOM generates valid non-zero span IDs
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_DEBUG=false
DD_TRACE_SECURE_RANDOM=true
--FILE--
<?php

DDTrace\start_span();
$id1 = DDTrace\active_span()->id;
DDTrace\close_span();

DDTrace\start_span();
$id2 = DDTrace\active_span()->id;
DDTrace\close_span();

// Both IDs must be non-empty numeric strings representing non-zero values.
var_dump(preg_match('/^\d+$/', $id1) === 1 && $id1 !== '0');
var_dump(preg_match('/^\d+$/', $id2) === 1 && $id2 !== '0');

// Two consecutive IDs drawn from a CSPRNG must differ.
var_dump($id1 !== $id2);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
