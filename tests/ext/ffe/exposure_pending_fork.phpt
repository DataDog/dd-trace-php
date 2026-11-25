--TEST--
A forked worker starts with an empty exposure batch while its parent keeps pending observations
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl required'); ?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
--FILE--
<?php
function recordPending() {
    DDTrace\Internal\record_ffe_exposure('flag', 'user', array(), 'A', 'on');
}
recordPending();
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    var_dump(DDTrace\Testing\flush_ffe_exposures());
    recordPending();
    var_dump(DDTrace\Testing\flush_ffe_exposures());
    exit(0);
}
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) throw new RuntimeException('child failed');
var_dump(DDTrace\Testing\flush_ffe_exposures());
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
