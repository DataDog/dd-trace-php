--TEST--
priority_sampling basic rule
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

if ($root->metrics["_dd.rule_psr"] == 0.3) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}
echo "_dd.p.upstream_services = {$root->meta["_dd.p.upstream_services"]}\n";
?>
--EXPECTREGEX--
Rule OK
_dd.p.upstream_services = MDA0LXJ1bGUtYmFzaWMucGhw\|(-1|2)\|3\|0.300
