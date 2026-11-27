--TEST--
Downstream curl requests: an app's own PREREQFUNCTION skips each transfer with one debug line
--DESCRIPTION--
G5.13, G3.9: a handle with the app's CURLOPT_PREREQFUNCTION (settable from
PHP 8.4) is skipped, with the reason prereqfunction logged once per transfer.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq("calling the app's PREREQFUNCTION");
if (!defined('CURLOPT_PREREQFUNCTION')) die('skip PHP 8.4+');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_skip_prereq.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
truncate_log();
var_dump(rinit());
$calls = 0;
$ch = curl_init("$base/json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_PREREQFUNCTION => function () use (&$calls) {
        $calls++;
        return CURL_PREREQFUNC_OK;
    }]);
curl_exec($ch);
curl_exec($ch);
echo "app callback calls: $calls\n";
cd_dump($ch);
echo preg_match_all('/\[debug\] Not observing a curl transfer: prereqfunction/',
    log_contents()), " skip line(s)\n";
var_dump(rshutdown());
echo count(ce_execs($helper)), " request_exec call(s)\n";
?>
--EXPECT--
bool(true)
app callback calls: 2
observed=no reason='prereqfunction' installed=no hops=0
2 skip line(s)
bool(true)
0 request_exec call(s)
