<?php

echo "fixture_child_ready\n";
\DDTrace\ffe_evaluate('flag', \DDTrace\FFE_STRING, null, array(), false);
if (getenv('PHP_FFE_EXPECT_THREAD_LISTENER')) {
    $found = false;
    foreach (file('/proc/net/unix') as $row) {
        $columns = preg_split('/\s+/', trim($row));
        if (isset($columns[7]) && $columns[3] === '00010000'
            && substr($columns[7], -strlen('@pid' . getmypid() . '.sock')) === '@pid' . getmypid() . '.sock') {
            $found = true;
            break;
        }
    }
    if (!$found) throw new RuntimeException('AUTO did not fall back to the process thread listener');
}
echo json_encode(\DDTrace\Internal\ffe_provider_state());
