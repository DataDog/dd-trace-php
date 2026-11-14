--TEST--
The chunk sampling mechanism follows the root's decision, not the inferred span's copy of the incoming _dd.p.dm
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=aws-server

DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
HTTP_X_DD_PROXY_STAGE=aws-prod

HTTP_X_DATADOG_TRACE_ID=1
HTTP_X_DATADOG_PARENT_ID=2
HTTP_X_DATADOG_SAMPLING_PRIORITY=1
HTTP_X_DATADOG_TAGS=_dd.p.dm=-0

METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
--GET--
foo=bar
--FILE--
<?php

\DDTrace\start_span(0.120);
// A manual keep changes the root's _dd.p.dm from the incoming -0 to -4.
\DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);
\DDTrace\close_span();

$chunk = dd_trace_serialize_closed_spans()['chunks'][0];
echo "priority: ", $chunk['priority'], "\n";
echo "sampling_mechanism: ", isset($chunk['sampling_mechanism']) ? $chunk['sampling_mechanism'] : 'unset', "\n";
foreach ($chunk['spans'] as $span) {
    echo $span['name'], " _dd.p.dm: ", isset($span['attributes']['_dd.p.dm']) ? $span['attributes']['_dd.p.dm'] : 'none', "\n";
}
?>
--EXPECT--
priority: 2
sampling_mechanism: 4
web.request _dd.p.dm: none
aws.apigateway _dd.p.dm: none
