--TEST--
Downstream curl requests: CURLFile and CURLStringFile parts are dropped, not kept alive
--DESCRIPTION--
The state holds values only, never an object passed to curl_setopt() (G4.4):
a CURLFile subclass in an array CURLOPT_POSTFIELDS is not kept alive by the
analysis, so its destructor runs when it would without it (ext/curl itself may
hold the array until the option is replaced, the handle reset or freed): every
scenario runs before rinit() (not analysed) as a baseline, which the analysed
run must match. The parts are dropped from the body the WAF gets (G7.7): no
file is read, nothing is parsed by MIME type. A body made only of such parts
is still a multipart body: a POST (and not a HEAD, after CURLOPT_NOBODY) with
no body address.
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
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 40, 'ok'), false);

class Noisy extends CURLFile {
    public static $log = [];
    public $cycle; // a reference back to the handle: a cycle if kept
    function __destruct() { self::$log[] = 'destructed'; }
}

$file = tempnam(sys_get_temp_dir(), 'cdf');
file_put_contents($file, '{"from":"file"}');

function scenario($base, $file, $end) {
    Noisy::$log = [];
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $f = new Noisy($file, 'application/json');
    $f->cycle = $ch;
    $fields = ['k' => 'v', 'f' => $f];
    if (class_exists('CURLStringFile')) { // PHP 8.1+
        $fields['s'] = new CURLStringFile('{"a":1}', 'a.json', 'application/json');
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    unset($fields);
    unset($f);
    Noisy::$log[] = 'after unset';
    curl_exec($ch);
    Noisy::$log[] = 'after exec';
    switch ($end) {
    case 'replace':
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'a=b');
        break;
    case 'reset':
        curl_reset($ch);
        break;
    case 'free':
        unset($ch);
        break;
    }
    Noisy::$log[] = 'after ' . $end;
    unset($ch);
    gc_collect_cycles();
    Noisy::$log[] = 'end';
    return Noisy::$log;
}

$baseline = [];
foreach (['replace', 'reset', 'free'] as $end) {
    $baseline[$end] = scenario($base, $file, $end);
}
var_dump(rinit());
foreach (['replace', 'reset', 'free'] as $end) {
    $log = scenario($base, $file, $end);
    echo "$end: ", $log === $baseline[$end] ? 'as baseline' :
        'DIFFERENT: ' . json_encode([$baseline[$end], $log]), "\n";
}

echo "-- only files, after NOBODY\n";
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['f' => new Noisy($file)]);
curl_exec($ch);
$d = curl_downstream_dump($ch);
echo "observed=", var_export($d['observed'], true), " method=", $d['options']['method'], "\n";
unlink($file);
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.method'])) {
        echo $id, ' ', $data['server.io.net.request.method'], ' ',
            json_encode(isset($data['server.io.net.request.body']) ?
                $data['server.io.net.request.body'] : null), "\n";
    }
}
?>
--EXPECT--
bool(true)
replace: as baseline
reset: as baseline
free: as baseline
-- only files, after NOBODY
observed=true method=POST
curl-1-0 POST {"k":"v"}
curl-2-0 POST {"k":"v"}
curl-3-0 POST {"k":"v"}
curl-4-0 POST null
