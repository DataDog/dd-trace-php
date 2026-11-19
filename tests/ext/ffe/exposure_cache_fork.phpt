--TEST--
A forked child sees its parent's sidecar admission
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
--ENV--
DD_SERVICE=php-ffe-fork-admission
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=1
DD_TRACE_CLI_ENABLED=1
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--FILE--
<?php
require_once __DIR__ . '/stubs/exposure_sidecar.inc';
function record($subject) {
    ffeAwaitExposureSidecar();
    return json_encode(DDTrace\Internal\record_ffe_exposure('flag', $subject, array(), 'allocation', 'on', 1));
}

$user = uniqid('user', true);
echo 'parent_first=', record($user), "\n";
echo 'parent_repeat=', record($user), "\n";
$pid = pcntl_fork();
if ($pid === 0) {
    echo 'child_first=', record($user), "\n";
    echo 'child_repeat=', record($user), "\n";
    exit(0);
}
pcntl_waitpid($pid, $status);
echo 'parent_after_fork=', record($user), "\n";
?>
--EXPECT--
parent_first="admitted"
parent_repeat="repeat"
child_first="repeat"
child_repeat="repeat"
parent_after_fork="repeat"
