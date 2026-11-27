--TEST--
Downstream sampler: limit and rate are validated, not cast or clamped
--DESCRIPTION--
A limit below 0 is rejected (1 is used), a rate outside [0, 1] is rejected
(0.5 is used), each with one warning per variable. A rate ZAI cannot decode
(1e-2) never reaches the accessor and is silently the default.
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_sampler_config.log
datadog.appsec.log_level=warning
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,should_send_downstream_bodies,downstream_sampler_set_counter};

include __DIR__ . '/inc/mock_helper.php';
require __DIR__ . '/inc/logging.php';

truncate_log();

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

function ini($name, $v) {
    return ini_set("datadog.api_security_$name", (string)$v);
}
function warnings($var) {
    return preg_match_all("/warning.*$var/", log_contents());
}

var_dump(rinit());

// limit -1 is rejected and 1 is used, not "unlimited"
ini('downstream_body_analysis_sample_rate', 1);
var_dump(ini('max_downstream_request_body_analysis', -1) !== false);
var_dump(should_send_downstream_bodies(), should_send_downstream_bodies());
var_dump(should_send_downstream_bodies());
var_dump(warnings('DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS'));

// limit 0 is valid: nothing is ever analysed, and nothing is warned about
var_dump(ini('max_downstream_request_body_analysis', 0) !== false);
var_dump(should_send_downstream_bodies());
var_dump(warnings('DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS'));
ini('max_downstream_request_body_analysis', 1000);

// out of range rates are rejected (0.5 is used), not clamped to 1 or 0
foreach ([2, -0.1] as $r) {
    var_dump(ini('downstream_body_analysis_sample_rate', $r) !== false);
    downstream_sampler_set_counter(0);
    var_dump(should_send_downstream_bodies()); // count 1: not sampled at 0.5
    var_dump(should_send_downstream_bodies()); // count 2: sampled at 0.5
}
var_dump(warnings('DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE'));

// ZAI rejects these silently, the value stays what it was
ini('downstream_body_analysis_sample_rate', 0.5);
var_dump(ini('downstream_body_analysis_sample_rate', '1e-2'));
var_dump(ini_get('datadog.api_security_downstream_body_analysis_sample_rate'));
var_dump(warnings('DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE'));

var_dump(rshutdown());
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
int(1)
bool(true)
bool(false)
int(1)
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
bool(true)
int(1)
bool(false)
string(3) "0.5"
int(1)
bool(true)
