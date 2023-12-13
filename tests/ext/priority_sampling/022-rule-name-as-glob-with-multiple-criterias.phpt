--TEST--
priority_sampling rule with name match, using glob
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_SAMPLING_RULES_FORMAT=glob
--FILE--
<?php

$tests = [
    ["webserver.non-matching", "web.request", "/bar", false],
    ["webserver", "web.request.non-matching", "/bar", false],
    ["webserver", "web.request", "/bar.non-matching", false],
    ["webserver", "web.request", "/b?r", true],
];

foreach ($tests as list($servicePattern, $namePattern, $resourcePatthern, $matches)) {
    ini_set("datadog.trace.sampling_rules", '[{"name":"' . $namePattern . '","service":"' . $servicePattern . '","resource":"' . $resourcePatthern . '","sample_rate":0.7},{"sample_rate": 0.3}]');

    $root = DDTrace\root_span();
    $root->service = "webserver";
    $root->name = "web.request";
    $root->resource = "/bar";

    DDTrace\get_priority_sampling();

    if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
        echo "As expected, rule $servicePattern, $namePattern, $resourcePatthern " . ($matches ? "matches" : "doesn't match") . "\n";
    } else {
        echo "Rule $servicePattern, $namePattern, $resourcePatthern " . ($matches ? "should have matched" : "shouldn't have matched") . "\n";
        var_dump($root->metrics);
    }

}
?>

--EXPECT--
As expected, rule webserver.non-matching, web.request, /bar doesn't match
As expected, rule webserver, web.request.non-matching, /bar doesn't match
As expected, rule webserver, web.request, /bar.non-matching doesn't match
As expected, rule webserver, web.request, /b?r matches
