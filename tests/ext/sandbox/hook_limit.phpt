--TEST--
Ensure the hook limit is not exceeded
--INI--
datadog.trace.hook_limit=2
--FILE--
<?php

function foo() {
}

$cb = function () use (&$invocations) {
    ++$invocations;
};

DDTrace\hook_function("foo", $cb);
DDTrace\install_hook("foo", $cb);
var_dump(DDTrace\hook_function("foo", $cb));
var_dump(DDTrace\install_hook("foo", $cb));

foo();
print "foo hooks were $invocations times invoked\n";

?>
--EXPECTF--
Could not add hook to foo with more than datadog.trace.hook_limit = 2 installed hooks in %s on line %d; This message is only displayed once. Append 'Once' DD_TRACE_DEBUG= to show all messages (e.g. DD_TRACE_DEBUG=Error,Once).
bool(false)
Could not add hook to foo with more than datadog.trace.hook_limit = 2 installed hooks in %s on line %d; This message is only displayed once. Append 'Once' DD_TRACE_DEBUG= to show all messages (e.g. DD_TRACE_DEBUG=Error,Once).
int(0)
foo hooks were 2 times invoked
