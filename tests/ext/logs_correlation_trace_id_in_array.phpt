--TEST--
Storing the logs correlation trace id shouldn't induce memory leaks
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_DEBUG=1
--FILE--
<?php

function sampleLog($context = []) {
    var_dump($context);
}

function modifyArray($context) {
    $context['dd.trace_id'] = \DDTrace\logs_correlation_trace_id();
    return $context;
}

function getHookFn() {
    return function (\DDTrace\HookData $hook) {
        $hook->args[0] = modifyArray($hook->args[0] ?? []);
        var_dump($hook->args[0]['dd.trace_id']);
        var_dump($hook->overrideArguments($hook->args));
    };
}

\DDTrace\install_hook('sampleLog', getHookFn());

\DDTrace\start_trace_span();

sampleLog();

\DDTrace\close_span();

?>
--EXPECT--
string(20) "11788048577503494824"
bool(true)
array(1) {
  ["dd.trace_id"]=>
  string(20) "11788048577503494824"
}
Flushing trace of size 2 to send-queue for http://localhost:8126
