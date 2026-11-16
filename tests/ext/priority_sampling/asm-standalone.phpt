--TEST--
priority_sampling is ignored when asm standalone is enabled
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3}]
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_APM_TRACING_ENABLED=0
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

var_dump(isset($root->metrics["_dd.rule_psr"]) ? "This should not be present": "Missing");
echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";
?>
--EXPECT--
string(7) "Missing"
_dd.p.dm = -0
