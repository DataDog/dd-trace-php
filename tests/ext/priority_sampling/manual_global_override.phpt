--TEST--
Global explicitly set priority sampling must be respected
--ENV--
DD_TRACE_SAMPLE_RATE=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

\DDTrace\set_priority_sampling(1);

$root = \DDTrace\start_span();

echo "Sampling: ", \DDTrace\get_priority_sampling(), "\n";
echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";
?>
--EXPECT--
Sampling: 1
_dd.p.dm = -4
