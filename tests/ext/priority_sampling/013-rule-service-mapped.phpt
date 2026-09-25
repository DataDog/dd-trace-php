--TEST--
priority_sampling service mapping
--ENV--
DD_TRACE_SAMPLING_RULES=[{"service": "mapped", "sample_rate": 0}, {"sample_rate": 1}]
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_SERVICE_MAPPING=unmapped:mapped
--FILE--
<?php
$root = \DDTrace\root_span();
$root->service = "unmapped";

\DDTrace\get_priority_sampling();

if ($root->attributes["_dd.rule_psr"] == 0) {
    echo "Rule OK\n";
} else {
    var_dump($root->attributes);
}
echo "_dd.p.dm = ", isset($root->attributes["_dd.p.dm"]) ? $root->attributes["_dd.p.dm"] : "-", "\n";
?>
--EXPECTREGEX--
Rule OK
_dd.p.dm = (-3|-)
