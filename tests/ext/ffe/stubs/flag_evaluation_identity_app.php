<?php
// Use the same flag and subject throughout. Only application identity may
// separate these observations; earlier observations must not be relabelled.
foreach (array(
    array(),
    array('service' => 'app-a', 'env' => 'test', 'version' => 'v1'),
    array('service' => 'app-b'),
    array('env' => 'production'),
    array('version' => 'v2'),
) as $settings) {
    foreach ($settings as $key => $value) {
        ini_set('datadog.' . $key, $value);
    }
    if (!\DDTrace\Internal\record_ffe_flag_evaluation(
        'same-flag', 'blue', null, null, array(), null, false, false, 1700000000123
    )) throw new RuntimeException('submission rejected');
}
dd_trace_synchronous_flush();
