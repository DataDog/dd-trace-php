--TEST--
Do not update distributed tracing context if a span is already active
--FILE--
<?php

DDTrace\start_span();
$oldContext = DDTrace\current_context();
var_dump(DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]));
var_dump($oldContext == DDtrace\current_context());

?>
--EXPECT--
bool(false)
bool(true)
