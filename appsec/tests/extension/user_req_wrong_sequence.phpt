--TEST--
User requests: regular sequence
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
datadog.appsec.log_level=warning
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\{start_span,close_span,switch_stack};
use function datadog\appsec\testing\dump_req_lifecycle_state;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createinitedRun([
    response_list(response_request_init([[['ok', []]], [], []])),
    response_list(response_request_shutdown([[['ok', []]], [], []])),
    response_list(response_request_init([[['ok', []]], [], []])),
    response_list(response_request_shutdown([[['ok', []]], [], []])),
]);

function d() {
    $d = dump_req_lifecycle_state();
    var_dump(array(
        'span' => !empty($d['span']) ? "span {$d['span']->id}" : '(none)',
        'shutdown_done_on_commit' => $d['shutdown_done_on_commit']
    ));
}
$span = start_span();
notify_start($span, array());
echo "# Double notify_start\n";
notify_start($span, array());
d();
close_span(100.0);
echo "After close_span\n";
d();

echo "\n# Start with closed span\n";
notify_start($span, array());

echo "\n# Commit span with closed span\n";
notify_commit($span, 200, array());

echo "\n# Commit unstarted span\n";
$span = start_span();
notify_commit($span, 200, array());
close_span();

echo "\n# Double commit\n";
$span = start_span();
notify_start($span, array());
notify_commit($span, 200, array());
notify_commit($span, 200, array());
d();

--EXPECTF--
# Double notify_start

Warning: DDTrace\UserRequest\notify_start(): Start of span already notified in %s on line %d
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
After close_span
array(2) {
  ["span"]=>
  string(6) "(none)"
  ["shutdown_done_on_commit"]=>
  bool(false)
}

# Start with closed span

Warning: DDTrace\UserRequest\notify_start(): Span already finished in %s on line %d

# Commit span with closed span

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Request commit callback called, but there is no root span currently associated through the request started span (or it was cleared already) in %s on line %d

# Commit unstarted span

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Request commit callback called, but there is no root span currently associated through the request started span (or it was cleared already) in %s on line %d

# Double commit

Warning: DDTrace\UserRequest\notify_commit(): [ddappsec] Request commit callback called twice for the same span in %s on line %d
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(true)
}

Warning: %s: [ddappsec] Request finished callback called, but there is no root span currently associated through the request started span (or it was cleared already). Resetting in Unknown on line %d
