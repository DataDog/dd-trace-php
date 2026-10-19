<?php
foreach (array('default', 'configured') as $flag) {
    if ($flag === 'configured') {
        ini_set('datadog.service', 'changed-service');
        ini_set('datadog.env', 'changed-env');
    }
    if (!\DDTrace\Internal\record_ffe_flag_evaluation(
        $flag, 'blue', null, null, array(), null, false, false, 1700000000123
    )) throw new RuntimeException('submission rejected');
}
dd_trace_synchronous_flush();
