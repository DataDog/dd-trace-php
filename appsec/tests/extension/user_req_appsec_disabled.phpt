--TEST--
User requests: no warnings when appsec is disabled
--INI--
extension=ddtrace.so
datadog.appsec.enabled=false
datadog.appsec.cli_start_on_rinit=false
datadog.appsec.log_level=warning
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit,set_blocking_function};
use function DDTrace\{close_span,start_span};

// The sequence the FrankenPHP and RoadRunner integrations run for every request
$span = start_span();
var_dump(notify_start($span, array()));
set_blocking_function($span, function () {});
var_dump(notify_commit($span, 200, array()));
close_span();
echo "done\n";
--EXPECT--
NULL
NULL
done
