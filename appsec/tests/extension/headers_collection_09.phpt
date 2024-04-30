--TEST--
Basic headers are collected when track_user_signup_event is triggered by automation and extended mode is not set
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING=safe
HTTP_X_FORWARDED_FOR=7.7.7.7
DD_TRACE_CLIENT_IP_HEADER_DISABLED=true
HTTP_X_FORWARDED_FOR=7.7.7.6,10.0.0.1
HTTP_X_CLIENT_IP=7.7.7.7
HTTP_X_REAL_IP=7.7.7.8
HTTP_X_FORWARDED=for="foo"
HTTP_X_CLUSTER_CLIENT_IP=7.7.7.9
HTTP_FORWARDED_FOR=7.7.7.10,10.0.0.1
HTTP_FORWARDED=for="foo"
HTTP_VIA=HTTP/1.1 GWA
HTTP_TRUE_CLIENT_IP=7.7.7.11
HTTP_CONTENT_TYPE=text/plain
HTTP_CONTENT_LENGTH=0
HTTP_CONTENT_ENCODING=utf-8
HTTP_CONTENT_LANGUATE=pt-PT
HTTP_HOST=myhost:8888
HTTP_USER_AGENT=my user agent
HTTP_ACCEPT=*/*
HTTP_ACCEPT_ENCODING=gzip
HTTP_ACCEPT_LANGUAGE=pt-PT
HTTP_MY_HEADER=my header value
HTTP_MY_OTHER_HEADER=my other header value
HTTP_X_AMZN_TRACE_ID=amazontraceid
HTTP_IGNORED_HEADER=ignored header
HTTP_CLOUDFRONT_VIEWER_JA3_FINGERPRINT=cloudfrontviewer
HTTP_CF_RAY=cfray
HTTP_X_CLOUD_TRACE_CONTEXT=cloudtracecontext
HTTP_X_APPGW_TRACE_ID=appgvtraceid
HTTP_X_SIGSCI_REQUESTID=sigscirequestid
HTTP_X_SIGSCI_TAGS=sigscitags
HTTP_AKAMAI_USER_RISK=akamaiuserisk
--GET--
key=val
--FILE--
<?php
use function datadog\appsec\testing\{rinit,ddtrace_rshutdown,rshutdown,mlog};
use const datadog\appsec\testing\log_level\DEBUG;
use function datadog\appsec\track_user_signup_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok'])),
    response_list(response_request_shutdown(['ok', [], new ArrayObject(), new ArrayObject()])),
], ['continuous' => true]);


rinit();
$helper->get_commands(); //ignore

track_user_signup_event("1234", [], true);

rshutdown();
$helper->get_commands(); //ignore



ddtrace_rshutdown();
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];

$headers = array_filter($tags, function ($key) { return strpos($key, "http.request.headers.") === 0;}, ARRAY_FILTER_USE_KEY);
var_dump($headers);

$helper->finished_with_commands();
?>
--EXPECTF--
array(11) {
  ["http.request.headers.akamai-user-risk"]=>
  string(13) "akamaiuserisk"
  ["http.request.headers.cf-ray"]=>
  string(5) "cfray"
  ["http.request.headers.user-agent"]=>
  string(13) "my user agent"
  ["http.request.headers.accept"]=>
  string(3) "*/*"
  ["http.request.headers.cloudfront-viewer-ja3-fingerprint"]=>
  string(16) "cloudfrontviewer"
  ["http.request.headers.x-appgw-trace-id"]=>
  string(12) "appgvtraceid"
  ["http.request.headers.x-sigsci-tags"]=>
  string(10) "sigscitags"
  ["http.request.headers.x-sigsci-requestid"]=>
  string(15) "sigscirequestid"
  ["http.request.headers.content-type"]=>
  string(10) "text/plain"
  ["http.request.headers.x-amzn-trace-id"]=>
  string(13) "amazontraceid"
  ["http.request.headers.x-cloud-trace-context"]=>
  string(17) "cloudtracecontext"
}
