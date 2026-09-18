--TEST--
OpenTelemetry unknown sampling fields survive fork and independent replacement
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl required'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function report(): void
{
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    preg_match('/(?:^|,)ot=[^,]*?(foo:[^;,]+)/', $headers['tracestate'], $matches);
    echo $matches[1] ?? '<absent>', PHP_EOL;
}

$root = DDTrace\start_span();
$root->tracestate = 'ot=rv:1234567890abcd;th:8;foo:parent';
$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('fork failed');
}
if ($pid === 0) {
    DDTrace\start_span();
    report();
    DDTrace\root_span()->tracestate = 'ot=rv:1234567890abcd;th:8;foo:child';
    report();
    DDTrace\close_span();
    exit;
}
pcntl_waitpid($pid, $status);
echo 'child exited cleanly: ', pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0 ? 'yes' : 'no', PHP_EOL;
report();
DDTrace\close_span();
?>
--EXPECT--
foo:parent
foo:child
child exited cleanly: yes
foo:parent
