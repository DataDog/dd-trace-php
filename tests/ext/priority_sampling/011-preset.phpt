--TEST--
priority_sampling preset decision retained
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 1}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_USER_REJECT);

\DDTrace\get_priority_sampling();

if (!isset($root->metrics["_dd.rule_psr"])) {
    echo "OK\n";
} else {
    echo "metrics[_dd.rule_psr] = {$root->metrics["_dd.rule_psr"]}\n";
}
echo "_dd.p.upstream_services = {$root->meta["_dd.p.upstream_services"]}\n";
?>
--EXPECT--
OK
_dd.p.upstream_services = MDExLXByZXNldC5waHA|-1|4|
