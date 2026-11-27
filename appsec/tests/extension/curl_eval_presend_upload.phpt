--TEST--
Downstream curl requests: the method and body evaluated before sending after CURLOPT_UPLOAD
--DESCRIPTION--
Before libcurl 8.1.0, CURLOPT_UPLOAD makes every request a PUT, whatever
CURLOPT_POSTFIELDS, CURLOPT_POST or CURLOPT_NOBODY set afterwards (until
UPLOAD=0 or HTTPGET); from 8.1.0 on, the last of them decides
(CVE-2023-28322). Distributions backported the change (Debian's 7.88.1),
so the version does not tell which: before 8.1.0, the pre-send evaluation
must not claim a method (nor the POSTFIELDS body) libcurl may not send: W1
omits them. A body from the READFUNCTION has no source in the options: W1
has none, and the read-function gate (G6.7) analyses it as libcurl reads it,
in a further request-stage call. Whatever the libcurl, what is evaluated
must match what the server got.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 30, 'ok'), false);
var_dump(rinit());
ce_execs($helper); // request_init
$old = datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x080100;

$json = ['Content-Type: application/json'];
$cases = [
    // (libcurl before 8.1.0 without the change: a PUT with no body; W1
    // omits the method and the body it is not sure of either way)
    'postfields' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{"from":"postfields"}');
    },
    // (the body comes from the READFUNCTION: W1 has none, the gate has it)
    'post' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POST, true);
        $sent = false;
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($ch, $fd, $n) use (&$sent) {
            if ($sent) {
                return '';
            }
            $sent = true;
            return '{"from":"readfunction"}';
        });
    },
    'nobody' => function ($ch) use ($json) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $json);
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILESIZE, 0);
        curl_setopt($ch, CURLOPT_NOBODY, true);
    },
];
foreach ($cases as $label => $setup) {
    $ch = curl_init("$base/echo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $setup($ch);
    file_put_contents($log, '');
    $r = curl_exec($ch);
    $calls = ce_execs($helper);
    $sent = explode(' ', trim(file_get_contents($log)))[0];
    $presend = $calls[0][2];
    $method = isset($presend['server.io.net.request.method']) ? $presend['server.io.net.request.method'] : null;
    $bodies = [];
    foreach ($calls as $c) {
        if (isset($c[2]['server.io.net.request.body'])) {
            $bodies[] = json_encode($c[2]['server.io.net.request.body']);
        }
    }
    $echoed = is_string($r) && $r !== '' ? json_encode(json_decode($r, true)) : null;
    $got = $method === null ? 'none'
        : ($method === $sent ? 'as sent' : "$method, but $sent sent");
    $expected = $old ? 'none' : 'as sent';
    // W1 claims the POSTFIELDS body only where it is sure libcurl sends it
    // (UPLOAD, then POSTFIELDS: from 8.1.0 on); one from the READFUNCTION
    // is read by the gate, whatever the method libcurl sends it with
    $expect_body = ($label === 'postfields' && !$old) || $label === 'post';
    if ($bodies !== [] && $bodies !== [$echoed]) {
        $body = json_encode($bodies) . ', but ' . var_export($echoed, true) . ' sent';
    } else {
        $body = ($bodies !== []) === $expect_body ? 'as expected'
            : ($expect_body ? 'missing' : 'unexpected');
    }
    echo "$label: method before sending: ",
        $got === $expected ? 'as expected' : "$got (expected: $expected)",
        "; body evaluated: $body\n";
}
var_dump(rshutdown());
@unlink($log);
?>
--EXPECT--
bool(true)
postfields: method before sending: as expected; body evaluated: as expected
post: method before sending: as expected; body evaluated: as expected
nobody: method before sending: as expected; body evaluated: as expected
bool(true)
