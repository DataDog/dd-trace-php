--TEST--
FFE count kill switch disables submission without disabling OTLP metrics
--ENV--
DD_FLAGGING_EVALUATION_COUNTS_ENABLED=false
DD_METRICS_OTEL_ENABLED=true
--FILE--
<?php
var_dump(\DDTrace\Internal\record_ffe_flag_evaluation(
    'flag', 'blue', 'allocation', 'subject', array('plan' => 'pro'), null, false, true
));
var_dump(\DDTrace\Internal\record_ffe_evaluation_metric(
    'flag', 'blue', 'SPLIT', null, 'allocation'
));
?>
--EXPECT--
bool(false)
bool(true)
