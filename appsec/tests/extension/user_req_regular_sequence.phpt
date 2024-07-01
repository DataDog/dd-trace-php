--TEST--
User requests: regular sequence
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\{start_span,close_span};
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

echo "Initial:\n";
d();
notify_start($span, array());
echo "After notify_start:\n";
d();
notify_commit($span, 200, array());
echo "After notify_commit:\n";
d();
close_span(100.0);
echo "After close_span():\n";
d();


echo "Initial:\n";
d();
$span = start_span();
$res = notify_start($span, array());
echo "After notify_start:\n";
d();
notify_commit($span, 200, array());
echo "After notify_commit:\n";
d();
close_span(100.0);
echo "After close_span():\n";
d();
--EXPECTF--
Initial:
array(2) {
  ["span"]=>
  string(6) "(none)"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
After notify_start:
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
After notify_commit:
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(true)
}
After close_span():
array(2) {
  ["span"]=>
  string(6) "(none)"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
Initial:
array(2) {
  ["span"]=>
  string(6) "(none)"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
After notify_start:
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
After notify_commit:
array(2) {
  ["span"]=>
  string(%d) "span %s"
  ["shutdown_done_on_commit"]=>
  bool(true)
}
After close_span():
array(2) {
  ["span"]=>
  string(6) "(none)"
  ["shutdown_done_on_commit"]=>
  bool(false)
}
