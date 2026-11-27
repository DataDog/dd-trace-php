--TEST--
Downstream curl requests: a handle that cannot be interposed is skipped with its reason
--DESCRIPTION--
G3.9 and G10.19: when libcurl refuses our functions for the handle, the
transfer is not observed: exactly one debug line "Not observing a curl
transfer: cannot_interpose" is written, no request_exec call is made, and the
transfer itself is unaffected. Once libcurl accepts them again the next
transfer is observed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_cannot_interpose.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_install_refuse,curl_downstream_installed_count};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));

function transfer()
{
    global $base;
    $ch = curl_init("$base/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return curl_exec($ch);
}

function lines()
{
    $log = log_contents();
    preg_match_all('/\[debug\] Not observing a curl transfer: (\w+)/', $log, $m);
    truncate_log();
    return implode(',', $m[1]);
}

var_dump(rinit());
truncate_log();

curl_downstream_install_refuse(true);
var_dump(strlen(transfer()) > 0);
echo "refused: [", lines(), "]\n";
echo count(ce_execs($helper)), " request_exec call(s)\n";
echo curl_downstream_installed_count(), " installed\n";

curl_downstream_install_refuse(false);
var_dump(strlen(transfer()) > 0);
echo "accepted: [", lines(), "]\n";
echo count(ce_execs($helper)), " request_exec call(s)\n";
var_dump(rshutdown());
?>
--EXPECT--
bool(true)
bool(true)
refused: [cannot_interpose]
0 request_exec call(s)
0 installed
bool(true)
accepted: []
2 request_exec call(s)
bool(true)
