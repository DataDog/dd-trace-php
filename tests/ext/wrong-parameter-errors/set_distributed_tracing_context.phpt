--TEST--
DDTrace_set_distributed_tracing_context is passed wrong parameters
--FILE--
<?php

declare(strict_types = 1);

try {
    \DDTrace\set_distributed_tracing_context("0");
} catch (ArgumentCountError $e) {
    echo "OK1\n";
}

try {
    \DDTrace\set_distributed_tracing_context("foo", ["foo"]);
} catch (TypeError $e) {
    echo "OK2\n";
}

try {
    \DDTrace\set_distributed_tracing_context(["foo"], "foo");
} catch (TypeError $e) {
    echo "OK3\n";
}

try {
    \DDTrace\set_distributed_tracing_context("foo", "foo", new StdClass());
} catch (TypeError $e) {
    echo "OK4\n";
}

try {
    \DDTrace\set_distributed_tracing_context("foo", "foo", "foo", new StdClass());
} catch (TypeError $e) {
    echo "OK5\n";
}

?>
--EXPECT--
OK1
OK2
OK3
OK4
OK5
