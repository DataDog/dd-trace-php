--TEST--
Downstream curl: a hop that ends without a response closes its subcontext without data
--DESCRIPTION--
After the request call of a transfer that fails (connection refused), the
extension sends a data-less last call: an empty map and subctx_last_call, no
rule_variant (it is not an evaluation, so no ssrf metrics are recorded for it).
A hop that did get a response is closed by its response call instead, also
when the transfer then fails (malformed chunk, refused redirect target): in
all cases every subcontext gets exactly one last call, and it is its final one.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('telling a hop with a response head from one without');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
var_dump(rinit());

echo "== error before the response\n";
$ch = curl_init("http://127.0.0.1:1/refused");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

echo "== error after the response head\n";
$ch = curl_init("$base/badchunk");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

echo "== redirect to a refused port\n";
$ch = curl_init("$base/redirect-to?u=" . urlencode("http://127.0.0.1:1/refused"));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
var_dump(curl_exec($ch));

var_dump(rshutdown());
$cmds = $helper->get_commands();
$execs = ce_execs_of($cmds);
foreach ($execs as list($id, $last, $data)) {
    // the closing calls without data
    if ($last && !$data) {
        echo "data-less last call to $id\n";
    }
}
ce_check_closed($execs);
foreach ($cmds as $c) {
    if ($c[0] === 'request_exec' && !$c[1][0]) {
        echo "rule_variant: ", $c[1][1]['rule_variant'] ?? '-', "\n";
        echo "rasp_rule: ", $c[1][1]['rasp_rule'] ?? '-', "\n";
    }
}
?>
--EXPECTF--
bool(true)
== error before the response
bool(false)
== error after the response head
bool(false)
== redirect to a refused port
bool(false)
bool(true)
data-less last call to curl-1-0
3 subcontext(s), all closed
rule_variant: -
rasp_rule: ssrf
