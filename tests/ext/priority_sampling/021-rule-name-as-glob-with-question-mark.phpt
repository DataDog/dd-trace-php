--TEST--
priority_sampling rule with name match, using glob
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_SAMPLING_RULES_FORMAT=glob
--FILE--
<?php

$tests = [
    ["fooname", "fooname", true],
    ["fooname**", "fooname", true],
    ["**fooname", "fooname", true],
    ["*", "fooname", true],
    ["???????", "fooname", true],
    ["??????", "fooname", false],
    ["??", "fooname", false],
    ["*?", "fooname", true],
    ["?*", "fooname", true],
    ["f*o*e", "fooname", true],
    ["f*o*m?", "fooname", true],
    ["f*x*m?", "fooname", false],
];

foreach ($tests as list($pattern, $name, $matches)) {
    ini_set("datadog.trace.sampling_rules", '[{"name":"' . $pattern . '","sample_rate":0.7},{"sample_rate": 0.3}]');

    $root = DDTrace\root_span();
    $root->name = $name;

    DDTrace\get_priority_sampling();

    if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
        echo "As expected, $pattern " . ($matches ? "matches" : "doesn't match") . " $name (name)\n";
    } else {
        echo "$pattern " . ($matches ? "should have matched" : "shouldn't have matched") . " $name (service). Metrics found were: \n";
        var_dump($root->metrics);
    }

    ini_set("datadog.trace_sampling_rules", '[{"service":"' . $pattern . '","sample_rate":0.7},{"sample_rate": 0.3]');

    $root = \DDTrace\root_span();
    $root->service = $name;

    \DDTrace\get_priority_sampling();

    if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
        echo "As expected, $pattern " . ($matches ? "matches" : "doesn't match") . " $name (service)\n";
    } else {
        echo "$pattern " . ($matches ? "should have matched" : "shouldn't have matched") . " $name (name). Metrics found were: \n";
        var_dump($root->metrics);
    }
}
?>

--EXPECT--
As expected, fooname matches fooname (name)
As expected, fooname matches fooname (service)
As expected, fooname** matches fooname (name)
As expected, fooname** matches fooname (service)
As expected, **fooname matches fooname (name)
As expected, **fooname matches fooname (service)
As expected, * matches fooname (name)
As expected, * matches fooname (service)
As expected, ??????? matches fooname (name)
As expected, ??????? matches fooname (service)
As expected, ?????? doesn't match fooname (name)
As expected, ?????? doesn't match fooname (service)
As expected, ?? doesn't match fooname (name)
As expected, ?? doesn't match fooname (service)
As expected, *? matches fooname (name)
As expected, *? matches fooname (service)
As expected, ?* matches fooname (name)
As expected, ?* matches fooname (service)
As expected, f*o*e matches fooname (name)
As expected, f*o*e matches fooname (service)
As expected, f*o*m? matches fooname (name)
As expected, f*o*m? matches fooname (service)
As expected, f*x*m? doesn't match fooname (name)
As expected, f*x*m? doesn't match fooname (service)
