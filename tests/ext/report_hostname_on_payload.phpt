--TEST--
DD_TRACE_REPORT_HOSTNAME reports the hostname on the payload, not on spans
--ENV--
DD_TRACE_REPORT_HOSTNAME=1
DD_HOSTNAME=my-host
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$payload = dd_trace_serialize_closed_spans();
var_dump($payload['hostname']);
var_dump(isset($payload['chunks'][0]['spans'][0]['attributes']['_dd.hostname']));

ini_set('datadog.hostname', '');
DDTrace\start_span();
DDTrace\close_span();
var_dump(dd_trace_serialize_closed_spans()['hostname'] === gethostname());
?>
--EXPECT--
string(7) "my-host"
bool(false)
bool(true)
