--TEST--
Downstream curl requests whose URL is not known are skipped; the known one is the URL of W1
--DESCRIPTION--
The pre-send call (W1) is made from the recorded URL; there is no fallback
that reads the URL back from libcurl. A curl_setopt_array() call that failed
partway applied exactly the elements before the one ext/curl rejected (G4.12:
they run as curl_setopt() calls), so the URL it set is known, and is the one
W1 carries, also over a URL the handle had before. A Stringable CURLOPT_URL is
converted once by the curl_setopt() hook, so its URL is known too. A URL that
could not be recorded exactly (a __toString() that throws) makes the transfer
an unknown_option one: it is skipped, with no WAF call and a debug log line.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// without it, no hop after the first is evaluated (G6.11)
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_eval_url_unknown.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
truncate_log();
var_dump(rinit());

class U
{
    public $u;
    public static $calls = 0;
    function __construct($u) { $this->u = $u; }
    function __toString() { self::$calls++; return $this->u; }
}

echo "== 1. curl_setopt_array(): URL applied, a later option rejected\n";
$ch = curl_init();
var_dump(curl_setopt_array($ch, [CURLOPT_URL => "$base/json?n=1",
    CURLOPT_RETURNTRANSFER => true, CURLOPT_SSLVERSION => 1000]));
var_dump(curl_exec($ch));

echo "== 2. the same, on a handle that had another URL\n";
$ch = curl_init("$base/json?stale=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_setopt_array($ch, [CURLOPT_URL => "$base/json?n=2", CURLOPT_SSLVERSION => 1000]));
var_dump(curl_exec($ch));

echo "== 3. Stringable CURLOPT_URL\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_setopt($ch, CURLOPT_URL, new U("$base/json?n=3")));
var_dump(curl_exec($ch));
echo "__toString() calls: ", U::$calls, "\n";

echo "== 4. __toString() throws: unknown_option, skipped\n";
class Throwing
{
    function __toString() { throw new Exception('boom'); }
}
if (PHP_VERSION_ID >= 70400) {
    $ch = curl_init("$base/json?n=4");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    try {
        curl_setopt($ch, CURLOPT_URL, new Throwing);
    } catch (Exception $e) {
        echo "threw: ", $e->getMessage(), "\n";
    }
    var_dump(curl_exec($ch));
} else {
    // the engine's fatal error cannot be caught
    echo "threw: boom\nbool(false)\n";
}

echo "== 5. no URL at all: nothing to evaluate\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

var_dump(rshutdown());
$log = log_contents();
$n = preg_match_all('/\[debug\] Not observing a curl transfer: unknown_option/', $log);
echo $n === (PHP_VERSION_ID >= 70400 ? 1 : 0) ? "unknown_option logged once\n" : "unexpected: $n line(s)\n";
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== 1. curl_setopt_array(): URL applied, a later option rejected
bool(false)
string(22) "{"status":"ok","n":42}"
== 2. the same, on a handle that had another URL
bool(false)
string(22) "{"status":"ok","n":42}"
== 3. Stringable CURLOPT_URL
bool(true)
string(22) "{"status":"ok","n":42}"
__toString() calls: 1
== 4. __toString() throws: unknown_option, skipped
threw: boom
bool(false)
== 5. no URL at all: nothing to evaluate
bool(false)
bool(true)
unknown_option logged once
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?n=1"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?n=2"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?n=3"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
