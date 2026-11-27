--TEST--
Downstream curl requests: a Stringable CURLOPT_URL is converted once and recorded as a string
--DESCRIPTION--
The curl_setopt() hook calls __toString() of an object URL itself, once, and
gives ext/curl the string, so the user's method does not run twice and the URL
is known without keeping the object (see curl_setopt_url_stringable_throws
for a method that throws).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));

class Url {
    public $n = 0;
    public $url;
    function __construct($url) { $this->url = $url; }
    function __toString() { $this->n++; return $this->url; }
}
class Throwing {
    function __toString() { throw new Exception('boom'); }
}

var_dump(rinit());
$u = new Url("$base/json?stringable=1");
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_setopt($ch, CURLOPT_URL, $u));
echo "__toString() calls after setopt: {$u->n}\n";
echo curl_exec($ch), "\n";
echo "__toString() calls after exec: {$u->n}\n";
echo ce_norm(curl_downstream_dump($ch)['options']['url']), "\n";

// the URL string is not kept by reference: an array element is converted too
$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_URL => new Url("$base/json?in_array=1")]);
echo curl_exec($ch), "\n";

var_dump(rshutdown());
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.url'])) {
        echo "$id ", ce_norm($data['server.io.net.url']), "\n";
    }
}
?>
--EXPECTF--
bool(true)
bool(true)
__toString() calls after setopt: 1
{"status":"ok","n":42}
__toString() calls after exec: 1
http://127.0.0.1:PORT/json?stringable=1
{"status":"ok","n":42}
bool(true)
curl-1-0 http://127.0.0.1:PORT/json?stringable=1
curl-2-0 http://127.0.0.1:PORT/json?in_array=1
