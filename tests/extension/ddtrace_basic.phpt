--TEST--
ddtrace integration — basic test
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--SKIPIF--
<?php
// on CI, the 5 minute timeout is sometimes exceeded
if (key_exists('CI', $_ENV) && $_ENV['CI'] === 'true') {
    require __DIR__ . "/inc/no_valgrind.php";
}
?>
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{rinit,ddtrace_rshutdown,mlog};
use const datadog\appsec\testing\log_level\DEBUG;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([['ok']], ['continuous' => true]);

mlog(DEBUG, "Call rinit");
echo "rinit\n";
var_dump(rinit());
mlog(DEBUG, "Call get_commands");
$c = $helper->get_commands();
echo 'number of commands: ', count($c), "\n";

// The global tag must exist before the span is created
\DDTrace\add_global_tag('ddappsec', 'true');

DDTrace\start_span();
var_dump(\DDTrace\root_span());

$trace_id = \DDTrace\trace_id();
echo 'trace id: ', $trace_id, "\n";
echo "ddtrace_rshutdown\n";
mlog(DEBUG, "Call ddtrace_rshutdown");
var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

mlog(DEBUG, "Call get_commands");
$commands = $helper->get_commands();
$sent_trace_id = $commands[0]['payload'][0][0]['trace_id'];
$tags = $commands[0]['payload'][0][0]['meta'];

echo 'sent trace id is the same as \DDTrace\trace_id(): ',
    $trace_id == $sent_trace_id ? 'yes' : 'no', "\n";

echo "tags:\n";
print_r($tags);

mlog(DEBUG, "Call finished_with_commands");
$helper->finished_with_commands();

?>
--EXPECTF--
rinit
bool(true)
number of commands: 2
object(DDTrace\SpanData)#%d (5) {
  ["name"]=>
  string(17) "ddtrace_basic.php"
  ["service"]=>
  string(12) "appsec_tests"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(2) {
    ["system.pid"]=>
    int(%d)
    ["ddappsec"]=>
    string(4) "true"
  }
  ["metrics"]=>
  array(0) {
  }
}
trace id: %s
ddtrace_rshutdown
bool(true)
sent trace id is the same as \DDTrace\trace_id(): yes
tags:
Array
(
    [system.pid] => %s
    [ddappsec] => true
)
