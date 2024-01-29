--TEST--
User requests: no root span
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
datado
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php

use function DDTrace\UserRequest\notify_start;
use function DDTrace\start_span;

$span = start_span();

notify_start($span, array());
--EXPECTF--
Fatal error: Uncaught TypeError: %s
Stack trace:
#0 %s DDTrace\UserRequest\notify_start(Object(DDTrace\SpanData), Array)
#1 {main}
  thrown in %s on line %d
