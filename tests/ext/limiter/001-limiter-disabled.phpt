--TEST--
rate limiter disabled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_RATE_LIMIT=0
DD_TRACE_SAMPLE_RATE=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

if (isset($root->metrics["_dd.limit_psr"])) {
    echo "Fail\n";

    var_dump($root);
} else {
    echo "OK\n";
}
?>
--EXPECT--
OK

