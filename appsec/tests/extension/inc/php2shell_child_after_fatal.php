<?php
// Child script for the php2shell_mitigation_after_fatal_*.phpt tests: a first
// deferred fatal, then a parse_str() that crosses the limit again after it.
// Its keys have the form kNNNNN<pad>[x] (P2S_KEYLEN bytes, default 3000, and
// P2S_SRC bytes of source in all): the insertion path of CVE-2026-109618,
// where a native bailout leaves a counted but uninitialised bucket. Env:
//   P2S_FATAL   the first fatal: alloc (P2S_ALLOC bytes in the main script),
//               soap (the same inside SoapClient::__doRequest(); ext/soap
//               turns the fatal into a SoapFault, caught here) or rpb
//               (request_parse_body() of the PUT body)
//   P2S_AFTER   where the parse_str() runs: shutdown (a shutdown function),
//               ob (an output callback) or main (after the catch, with soap)
//   P2S_FILL    before the parse_str(), usage is filled up to that many bytes
//               above the usage there
//   P2S_LIMITS  instead of the parse_str(), in a shutdown function: print the
//               request-level limits, set memory_limit to each value of this
//               comma-separated list and print them again, then grow the
//               usage to P2S_GROW bytes and print them from an output callback
// An output callback registered before the parse_str() prints CHECK count=N
// iter=M bad=B: count() against what foreach visits. Lines go to stderr:
// output is discarded on a memory error.
function p2s_env($name, $default = null) {
    $v = getenv($name);
    return $v === false || $v === '' ? $default : $v;
}
function p2s_safe_point() {}
function p2s_log($m) {
    file_put_contents('php://stderr', "$m\n");
}
function p2s_limits($label) {
    $st = datadog\appsec\testing\php2shell_mitigation_request_status();
    p2s_log("LIMITS $label armed=" . var_export($st['armed'], true)
        . " soft={$st['soft_limit']} hard={$st['hard_limit']}"
        . " usage=" . memory_get_usage(true));
}
function p2s_check() {
    ob_start(function ($buf) {
        global $arr;
        $n = 0;
        $bad = 0;
        foreach ($arr as $k => $v) {
            $n++;
            if (!is_string($k) || $k[0] !== 'k' || !is_array($v)) {
                $bad++;
            }
        }
        p2s_log("CHECK count=" . count($arr) . " iter=$n bad=$bad");
        return $buf;
    });
}
function p2s_parse() {
    global $arr, $src;
    $fill = (int)p2s_env('P2S_FILL', 0);
    $f = $fill > 0 ? str_repeat('f', $fill) : '';
    p2s_log("PARSE usage=" . memory_get_usage(true));
    parse_str($src, $arr);
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    // $f is printed so that opcache cannot free the filler early.
    p2s_log("PARSED count=" . count($arr) . " fill=" . strlen($f));
}
function p2s_after_limits() {
    p2s_limits('after');
    foreach (explode(',', p2s_env('P2S_LIMITS')) as $ml) {
        ini_set('memory_limit', $ml);
        // An allocation opcache cannot fold, so that our handlers see the
        // change.
        $x = str_repeat('a', 100 * strlen($ml));
        p2s_limits($ml);
    }
    ob_start(function ($buf) {
        p2s_limits('grown');
        return $buf;
    });
    $g = str_repeat('g', (int)p2s_env('P2S_GROW') - memory_get_usage(true));
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
    p2s_log("GROWN len=" . strlen($g));
}

$fatal = p2s_env('P2S_FATAL', 'alloc');
$after = p2s_env('P2S_AFTER', 'shutdown');
$arr = [];
$pad = str_repeat('Z', (int)p2s_env('P2S_KEYLEN', 3000) - 6);
$src = '';
for ($i = 0; strlen($src) < (int)p2s_env('P2S_SRC', 0); $i++) {
    $src .= sprintf('k%05d', $i) . $pad . '[x]=&';
}
unset($pad);

if ($after === 'shutdown') {
    register_shutdown_function(function () {
        if (p2s_env('P2S_LIMITS') !== null) {
            p2s_after_limits();
            return;
        }
        p2s_check();
        p2s_parse();
    });
} elseif ($after === 'ob') {
    p2s_check();
    ob_start(function ($buf) {
        p2s_parse();
        return $buf;
    });
}

p2s_log("BASE usage=" . memory_get_usage(true) . " srclen=" . strlen($src));
if ($fatal === 'soap') {
    class P2sSoapClient extends SoapClient {
        public $big;
        function __doRequest($request, $location, $action, $version, $one_way = 0, ...$rest): ?string {
            $this->big = str_repeat('x', (int)p2s_env('P2S_ALLOC'));
            p2s_safe_point();
            for ($i = 0; $i < 2; $i++) {}
            p2s_log("DOREQUEST RAN");
            return '';
        }
    }
    $c = new P2sSoapClient(null, ['location' => 'http://127.0.0.1:1/', 'uri' => 'urn:p2s', 'exceptions' => true]);
    try {
        $c->__soapCall('f', []);
    } catch (SoapFault $e) {
        p2s_log("CAUGHT " . $e->getMessage());
    }
    if ($after === 'main') {
        p2s_check();
        p2s_parse();
    }
} elseif ($fatal === 'rpb') {
    request_parse_body();
} else {
    $s = str_repeat('x', (int)p2s_env('P2S_ALLOC'));
    p2s_safe_point();
    for ($i = 0; $i < 2; $i++) {}
}
p2s_log("SCRIPT RAN");
