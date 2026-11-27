--TEST--
Invalid sampler configuration is not reported from libcurl's callbacks
--DESCRIPTION--
With a negative limit and a rate above 1, a transfer completes and each
invalid variable is warned about once, from the sampling decision outside of
libcurl's callbacks; the callback only reads sanitised values (it must not
allocate or run PHP code), so no PHP warning or error handler call occurs.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_sampler_config_callback.log
datadog.appsec.log_level=warning
datadog.api_security_max_downstream_request_body_analysis=-1
datadog.api_security_downstream_body_analysis_sample_rate=2
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
require __DIR__ . '/inc/logging.php';
truncate_log();
$handled = 0;
set_error_handler(function () use (&$handled) { $handled++; return true; });
$base = cd_server_start();

$ch = curl_init("$base/json?a=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
var_dump($handled);
foreach (['MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS',
          'DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE'] as $v) {
    var_dump(preg_match_all("/warning.*$v/", log_contents()) <= 1);
}
?>
--EXPECT--
string(22) "{"status":"ok","n":42}"
int(0)
bool(true)
bool(true)
