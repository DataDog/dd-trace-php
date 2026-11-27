--TEST--
Downstream curl requests: clone of a handle whose transfer is observed
--DESCRIPTION--
`clone $ch` copies the libcurl options of $ch (curl_easy_duphandle()),
including our debug and prereq callbacks while $ch's transfer is observed.
The clone must not keep them: its own CURLOPT_VERBOSE output goes where the
app says, its requests do not feed (or close) the evaluation of $ch's
transfer, and it is tracked like a curl_copy_handle() copy, so its own
transfer is evaluated in its own subcontext.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('seeing the response head while the transfer runs');
if (PHP_VERSION_ID < 80000) die('skip PHP 8+: curl handles are objects');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump,curl_downstream_installed_count,curl_downstream_tracking};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

$mh = curl_multi_init();
$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_multi_add_handle($mh, $ch));
// cloned once the response head was received
$t = microtime(true);
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    $d = curl_downstream_dump($ch);
    $head = !empty($d['response']) && $d['response']['head_complete'];
} while ($running && !$head && microtime(true) - $t < 5);
echo 'head received, transfer running: ', cd_yn($head && $running), "\n";
echo 'installed: ', curl_downstream_installed_count(), "\n";

// the app's own verbose output on a clone
$verbose = clone $ch;
$errf = tempnam(sys_get_temp_dir(), 'verbose');
$fp = fopen($errf, 'w+');
curl_setopt($verbose, CURLOPT_URL, "$base/json");
curl_setopt($verbose, CURLOPT_VERBOSE, true);
curl_setopt($verbose, CURLOPT_STDERR, $fp);
var_dump(curl_exec($verbose));
fclose($fp);
$out = file_get_contents($errf);
@unlink($errf);
echo 'clone verbose output has the request: ',
    cd_yn(strpos($out, '> GET /json HTTP/1.1') !== false), "\n";
cd_dump($verbose, 'verbose clone');

// a clone evaluated on its own
$c2 = clone $ch;
curl_setopt($c2, CURLOPT_URL, "$base/json");
var_dump(curl_exec($c2));
echo 'installed: ', curl_downstream_installed_count(), "\n";

do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
var_dump(curl_multi_getcontent($ch));
curl_multi_remove_handle($mh, $ch);
echo 'installed: ', curl_downstream_installed_count(), "\n";

// a clone of a handle with a WRITEFUNCTION: it gets the app's own callable,
// and its transfer is evaluated
$got = [];
$src = curl_init("$base/json");
curl_setopt($src, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$got, &$src) {
    $got[] = ($h === $src ? 'source' : 'clone') . ': ' . $data;
    return strlen($data);
});
curl_multi_add_handle($mh, $src);
$c3 = clone $src;
curl_setopt($c3, CURLOPT_URL, "$base/xml");
var_dump(curl_exec($c3));
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
curl_multi_remove_handle($mh, $src);
echo implode("\n", $got), "\n";

// a clone of a handle without state has none either
curl_downstream_tracking(false);
$bare = curl_init("$base/json");
curl_downstream_tracking(true);
$bare_clone = clone $bare;
echo 'clone of a handle without state: ',
    var_export(curl_downstream_dump($bare_clone), true), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
$groups = [];
foreach ($execs as $e) {
    $groups[$e[0]][] = $e;
}
ksort($groups);
ce_print(array_merge(...array_values($groups)));
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
int(0)
head received, transfer running: yes
installed: 1
string(22) "{"status":"ok","n":42}"
clone verbose output has the request: yes
== verbose clone
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: []
  resp body: '' complete=no truncated=no
string(22) "{"status":"ok","n":42}"
installed: 1
string(10) "0123456789"
installed: 0
bool(true)
clone: <doc><item attr="v">text</item></doc>
source: {"status":"ok","n":42}
clone of a handle without state: NULL
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-3-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-4-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/xml"}
curl-4-0 last {"server.io.net.response.body":{"doc":[{"item":[{"@attr":"v"},"text"]}]},"server.io.net.response.headers":{"connection":["close"],"content-length":["37"],"content-type":["application/xml"]},"server.io.net.response.status":"200"}
curl-5-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-5-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
5 subcontext(s), all closed
