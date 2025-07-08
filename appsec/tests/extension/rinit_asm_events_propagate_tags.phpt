--TEST--
Asm events are added under _dd.p.ts
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
datadog.appsec.enabled=1
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_TAGS=_dd.p.custom_tag=inherited,_dd.p.second_tag=bar
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,ddtrace_rshutdown,root_span_get_meta};
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([
            [['record', []]],
            ['{"found":"attack"}','{"another":"attack"}'],
            true
    ])),
    response_list(
     response_request_shutdown(
          [[['record', []]], ['{"yet another":"attack"}'], true, ["rshutdown_tag" => "rshutdown_value"], ["rshutdown_metric" => 2.1]]
     )
    ),
], ['continuous' => true]);

echo "rinit\n";
var_dump(rinit());
$helper->get_commands(); //ignore

$context = DDTrace\current_context();
echo "ASM is enabled on _dd.p.ts propagated tags? ";
echo isset($context['distributed_tracing_propagated_tags']['_dd.p.ts']) && $context['distributed_tracing_propagated_tags']['_dd.p.ts'] === "02" ? "Yes": "No";
echo PHP_EOL;

echo "rshutdown\n";
var_dump(rshutdown());
$helper->get_commands(); //ignore

echo "ddtrace_rshutdown\n";
var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];

echo "_dd.p.appsec? ";
echo isset($tags['_dd.p.ts']) && $tags['_dd.p.ts'] === "02" ? "Yes": "No";
echo PHP_EOL;

$helper->finished_with_commands();

?>
--EXPECTF--
rinit
bool(true)
ASM is enabled on _dd.p.ts propagated tags? Yes
rshutdown
bool(true)
ddtrace_rshutdown
bool(true)
_dd.p.appsec? Yes
