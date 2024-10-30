--TEST--
Foo
--ENV--
DD_SERVICE=version_test
DD_VERSION=5.2.0
DD_TRACE_DEBUG=1
--FILE--
<?php

$s1 = \DDTrace\start_trace_span();
$s1->name = "s1";
\DDTrace\close_span();

$s2 = \DDTrace\start_trace_span();
$s2->name = "s2";
$s2->service = "no dd_service";
\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
[ddtrace] [span] Encoding span %d: trace_id=%s, name='s1', service='version_test', resource: 's1', type 'cli' with tags: runtime-id='%s', _dd.p.dm='-0', version='5.2.0', _dd.p.tid='%s'; and metrics: process_id='%d', _dd.agent_psr='1', _sampling_priority_v1='1'
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://localhost:8126
[ddtrace] [span] Encoding span %d: trace_id=%s, name='s2', service='no dd_service', resource: 's2', type 'cli' with tags: runtime-id='%s', _dd.p.dm='-0', _dd.p.tid='%s'; and metrics: process_id='%d', _dd.agent_psr='1', _sampling_priority_v1='1'
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://localhost:8126
array(0) {
}
[ddtrace] [info] No finished traces to be sent to the agent
