--TEST--
priority_sampling rule with name match, using glob
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
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
    ["fooname", "FOONAME", true],
    ["fooNAME", "FOOname", true],
    ["fooNAME", "FOOname", true],
    ["[ooNAM]", "{OOnam}", false],
    ["{ooNAM}", "[OOnam]", false],
    ["*", 1.2, true],
    ["****", 1.2, true],
    ["", 1.2, false],
    ["1.2", 1.2, false],
    ["", 1, false],
    ["1", 1, true],
    ["1", 1.0, true],
    ["1*", 1.0, true],
    ["1*", 1, true],
    ["1*", 10, true],
    ["1*", 0, false],
    ["true", true, true],
    ["truee", true, false],
    ["*", true, true],
    ["FALSE", false, true],
    ["FALS", false, false],
    ["", null, true],
    ["*", null, true],
    ["?", null, false],
];

foreach ($tests as list($pattern, $name, $matches)) {
    if (\is_string($name)) {
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

        ini_set("datadog.trace.sampling_rules", '[{"service":"' . $pattern . '","sample_rate":0.7},{"sample_rate": 0.3}]');

        $root = \DDTrace\root_span();
        $root->service = $name;

        \DDTrace\get_priority_sampling();

        if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
            echo "As expected, $pattern " . ($matches ? "matches" : "doesn't match") . " $name (service)\n";
        } else {
            echo "$pattern " . ($matches ? "should have matched" : "shouldn't have matched") . " $name (service). Metrics found were: \n";
            var_dump($root->metrics);
        }

        ini_set("datadog.trace.sampling_rules", '[{"resource":"' . $pattern . '","sample_rate":0.7},{"sample_rate": 0.3}]');

        $root = \DDTrace\root_span();
        $root->resource = $name;

        \DDTrace\get_priority_sampling();

        if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
            echo "As expected, $pattern " . ($matches ? "matches" : "doesn't match") . " $name (resource)\n";
        } else {
            echo "$pattern " . ($matches ? "should have matched" : "shouldn't have matched") . " $name (resource). Metrics found were: \n";
            var_dump($root->metrics);
        }
    } else {
        ini_set("datadog.trace.sampling_rules", '[{"tags":{"foo":"' . $pattern . '"},"sample_rate":0.7},{"sample_rate": 0.3}]');

        $root = \DDTrace\root_span();
        $root->meta["foo"] = $name;

        \DDTrace\get_priority_sampling();

        $name = var_export($name, true);
        if ($root->metrics["_dd.rule_psr"] == ($matches ? 0.7 : 0.3)) {
            echo "As expected, $pattern " . ($matches ? "matches" : "doesn't match") . " $name (tag)\n";
        } else {
            echo "$pattern " . ($matches ? "should have matched" : "shouldn't have matched") . " $name (tag). Metrics found were: \n";
            var_dump($root->metrics);
        }
    }
}
?>

--EXPECT--
As expected, fooname matches fooname (name)
As expected, fooname matches fooname (service)
As expected, fooname matches fooname (resource)
As expected, fooname** matches fooname (name)
As expected, fooname** matches fooname (service)
As expected, fooname** matches fooname (resource)
As expected, **fooname matches fooname (name)
As expected, **fooname matches fooname (service)
As expected, **fooname matches fooname (resource)
As expected, * matches fooname (name)
As expected, * matches fooname (service)
As expected, * matches fooname (resource)
As expected, ??????? matches fooname (name)
As expected, ??????? matches fooname (service)
As expected, ??????? matches fooname (resource)
As expected, ?????? doesn't match fooname (name)
As expected, ?????? doesn't match fooname (service)
As expected, ?????? doesn't match fooname (resource)
As expected, ?? doesn't match fooname (name)
As expected, ?? doesn't match fooname (service)
As expected, ?? doesn't match fooname (resource)
As expected, *? matches fooname (name)
As expected, *? matches fooname (service)
As expected, *? matches fooname (resource)
As expected, ?* matches fooname (name)
As expected, ?* matches fooname (service)
As expected, ?* matches fooname (resource)
As expected, f*o*e matches fooname (name)
As expected, f*o*e matches fooname (service)
As expected, f*o*e matches fooname (resource)
As expected, f*o*m? matches fooname (name)
As expected, f*o*m? matches fooname (service)
As expected, f*o*m? matches fooname (resource)
As expected, f*x*m? doesn't match fooname (name)
As expected, f*x*m? doesn't match fooname (service)
As expected, f*x*m? doesn't match fooname (resource)
As expected, fooname matches FOONAME (name)
As expected, fooname matches FOONAME (service)
As expected, fooname matches FOONAME (resource)
As expected, fooNAME matches FOOname (name)
As expected, fooNAME matches FOOname (service)
As expected, fooNAME matches FOOname (resource)
As expected, fooNAME matches FOOname (name)
As expected, fooNAME matches FOOname (service)
As expected, fooNAME matches FOOname (resource)
As expected, [ooNAM] doesn't match {OOnam} (name)
As expected, [ooNAM] doesn't match {OOnam} (service)
As expected, [ooNAM] doesn't match {OOnam} (resource)
As expected, {ooNAM} doesn't match [OOnam] (name)
As expected, {ooNAM} doesn't match [OOnam] (service)
As expected, {ooNAM} doesn't match [OOnam] (resource)
As expected, * matches 1.2 (tag)
As expected, **** matches 1.2 (tag)
As expected,  doesn't match 1.2 (tag)
As expected, 1.2 doesn't match 1.2 (tag)
As expected,  doesn't match 1 (tag)
As expected, 1 matches 1 (tag)
As expected, 1 matches 1.0 (tag)
As expected, 1* matches 1.0 (tag)
As expected, 1* matches 1 (tag)
As expected, 1* matches 10 (tag)
As expected, 1* doesn't match 0 (tag)
As expected, true matches true (tag)
As expected, truee doesn't match true (tag)
As expected, * matches true (tag)
As expected, FALSE matches false (tag)
As expected, FALS doesn't match false (tag)
As expected,  matches NULL (tag)
As expected, * matches NULL (tag)
As expected, ? doesn't match NULL (tag)
