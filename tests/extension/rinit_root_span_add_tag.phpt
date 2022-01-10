--TEST--
Test ddtrace_root_span_add_tag
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_ENV=staging
DD_VERSION=0.42.69
REQUEST_URI=/my/ur%69/
SCRIPT_NAME=/my/uri.php
PATH_INFO=/ur%69/
REQUEST_METHOD=GET
URL_SCHEME=http
HTTP_CONTENT_TYPE=text/plain
HTTP_CONTENT_LENGTH=0
--GET--
key=val
--SKIPIF--
<?php
include __DIR__ . '/inc/ddtrace_version.php';
ddtrace_version_at_least('0.67.0');
?>
--FILE--
<?php
use function datadog\appsec\testing\{rinit,ddtrace_rshutdown,mlog};
use const datadog\appsec\testing\log_level\DEBUG;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([['ok']], ['continuous' => true]);

var_dump(rinit());
$helper->get_commands();

var_dump(\datadog\appsec\testing\root_span_add_tag("ddappsec", "true"));
var_dump(\datadog\appsec\testing\root_span_add_tag("ddappsec", "true"));

var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

mlog(DEBUG, "Call get_commands");
$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];

echo "tags:\n";
print_r($tags);

$helper->finished_with_commands();
?>
--EXPECTF--
bool(true)
bool(true)
bool(false)
bool(true)
tags:
Array
(
    [system.pid] => %d
    [http.method] => GET
    [http.url] => %S/my/ur%69/
    [version] => 0.42.69
    [env] => staging
    [ddappsec] => true
    [http.status_code] => 200
)
