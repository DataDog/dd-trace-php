--TEST--
FFE exposure cache starts empty in a forked child
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--FILE--
<?php
function record($subject) {
    return json_encode(DDTrace\Internal\record_ffe_exposure('flag', $subject, array(), 'allocation', 'on', 1));
}

echo 'parent_first=', record('user'), "\n";
echo 'parent_repeat=', record('user'), "\n";
$pid = pcntl_fork();
if ($pid === 0) {
    echo 'child_first=', record('user'), "\n";
    echo 'child_repeat=', record('user'), "\n";
    exit(0);
}
pcntl_waitpid($pid, $status);
echo 'parent_after_fork=', record('user'), "\n";
?>
--EXPECT--
parent_first=true
parent_repeat=false
child_first=true
child_repeat=false
parent_after_fork=false
