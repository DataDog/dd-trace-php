--TEST--
A curl transfer outside an active request logs rasp.rule.skipped
--DESCRIPTION--
Before rinit and after rshutdown no WAF call is made, and the transfer is
counted as a skipped RASP evaluation (reason out-of-request), as
push_addresses does. The metric is visible as a debug log line.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_skipped.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
$re = '/Telemetry metric rasp\.rule\.skipped added with tags rule_type:ssrf,rule_variant:request,reason:out-of-request/';
truncate_log();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(strlen(curl_exec($ch)));
echo "before request: ", preg_match_all($re, log_contents()), "\n";
var_dump(rinit());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
echo "in request: ", preg_match_all($re, log_contents()), "\n";
var_dump(rshutdown());
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
echo "after request: ", preg_match_all($re, log_contents()), "\n";
// transfers that are not evaluated for other reasons (G6.12: not http) are
// not counted as skipped RASP evaluations, in or out of a request
$any = '/Telemetry metric rasp\.rule\.skipped/';
truncate_log();
foreach (['file://' . __FILE__, 'ftp://127.0.0.1:1/x'] as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
}
echo "non-http out of request: ", preg_match_all($any, log_contents()), "\n";
var_dump(rinit());
foreach (['file://' . __FILE__, 'ftp://127.0.0.1:1/x'] as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
}
echo "non-http in request: ", preg_match_all($any, log_contents()), "\n";
?>
--EXPECT--
int(22)
before request: 1
bool(true)
in request: 1
bool(true)
after request: 2
non-http out of request: 0
bool(true)
non-http in request: 0
