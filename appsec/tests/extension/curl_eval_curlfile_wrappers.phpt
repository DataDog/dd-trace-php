--TEST--
Downstream curl requests: CURLFile parts are never read nor opened by the analysis
--DESCRIPTION--
A CURLFile in an array CURLOPT_POSTFIELDS is dropped from the body the WAF
gets (G7.7): the analysis opens nothing, whatever the name (a path, a local
file:// URL, a user stream wrapper counting its opens, a plain path once the
app replaced the "file" wrapper) and does not read its properties (a
subclass' __get() is not called). Each case runs before rinit() (not
analysed) as a baseline, which the analysed run must match.
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 40, 'ok'), false);

class CountingWrapper {
    public static $opens = 0;
    public $context;
    private $data = '{"from":"wrapper"}';
    private $pos = 0;
    function stream_open($path, $mode, $options, &$opened) { self::$opens++; return true; }
    function stream_read($n) { $r = (string)substr($this->data, $this->pos, $n); $this->pos += strlen($r); return $r; }
    function stream_eof() { return $this->pos >= strlen($this->data); }
    function stream_stat() { return ['size' => strlen($this->data), 'mode' => 0100644]; }
    function url_stat($path, $flags) { return $this->stream_stat(); }
    function stream_seek($o, $w) { $this->pos = $o; return true; }
    function stream_tell() { return $this->pos; }
    function stream_close() {}
}
stream_wrapper_register('counting', 'CountingWrapper');

class GetCounting extends CURLFile {
    public static $gets = 0;
    // Counts the calls. Before PHP 8.1 it returns null, not a string: ext/curl
    // there never frees the value it reads through __get(), and in a ZTS build
    // a returned literal is a copy that is reported as leaked (reproduced with
    // no AppSec involved). From 8.1 the unset typed property rejects null.
    function __get($name) {
        self::$gets++;
        return PHP_VERSION_ID < 80100 ? null : 'text/plain';
    }
}

$file = tempnam(sys_get_temp_dir(), 'cdf');
file_put_contents($file, '{"from":"file"}');

function run($base, $label, $make) {
    CountingWrapper::$opens = 0;
    GetCounting::$gets = 0;
    $ch = curl_init("$base/len");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_setopt($ch, CURLOPT_POSTFIELDS, ['k' => 'v', 'f' => $make()]);
    @curl_exec($ch);
    return [CountingWrapper::$opens, GetCounting::$gets];
}

$cases = [
    'wrapper' => function () { return new CURLFile('counting://x', 'application/json'); },
    'path' => function () use ($file) { return new CURLFile($file, 'application/json'); },
    'file url' => function () use ($file) { return new CURLFile("file://$file", 'application/json'); },
    'unset mime' => function () use ($file) {
        $f = new GetCounting($file, 'application/json');
        unset($f->mime);
        return $f;
    },
    'replaced file wrapper' => function () use ($file) {
        stream_wrapper_unregister('file');
        stream_wrapper_register('file', 'CountingWrapper');
        return new CURLFile($file, 'application/json');
    },
];
$baseline = [];
foreach ($cases as $label => $make) {
    $baseline[$label] = run($base, $label, $make);
    if ($label === 'replaced file wrapper') {
        stream_wrapper_restore('file');
    }
}
var_dump(rinit());
foreach ($cases as $label => $make) {
    $analysed = run($base, $label, $make);
    if ($label === 'replaced file wrapper') {
        stream_wrapper_restore('file');
    }
    echo "$label: ", $analysed === $baseline[$label] ? 'as baseline' :
        'DIFFERENT: ' . json_encode([$baseline[$label], $analysed]), "\n";
}
unlink($file);
// Only PHP 7.3 built against libcurl >= 7.56 fails here: it builds the form
// with the mime API and curl_mime_filedata(), which stats each name at once,
// so curl_setopt() fails (CURLE_READ_ERROR) for a name that is no local file
// ("counting://x", "file://..."): no body is applied and the request has none,
// as the baseline run shows. PHP 7.0 to 7.2 use curl_formadd(), which libcurl
// 7.88 does not open until sending, and PHP 7.4 and later feed the mime parts
// through a read callback on a PHP stream: both accept the setopt and apply
// the body, whose CURLFile part is dropped. Probe the behaviour rather than
// the version, as it also depends on the libcurl.
$probe = curl_init();
$nobody = @curl_setopt($probe, CURLOPT_POSTFIELDS,
    ['f' => new CURLFile('counting://x')]) ? [] : ['curl-1-0', 'curl-3-0'];
foreach (ce_execs($helper) as list($id, $last, $data)) {
    if (isset($data['server.io.net.request.method'])) { // before sending
        $body = isset($data['server.io.net.request.body']) ?
            $data['server.io.net.request.body'] : null;
        if (in_array($id, $nobody, true)) {
            echo $id, ' ', $body === null ? '{"k":"v"}' : 'DIFFERENT: ' .
                json_encode($body), "\n";
            continue;
        }
        echo $id, ' ', json_encode($body), "\n";
    }
}
?>
--EXPECT--
bool(true)
wrapper: as baseline
path: as baseline
file url: as baseline
unset mime: as baseline
replaced file wrapper: as baseline
curl-1-0 {"k":"v"}
curl-2-0 {"k":"v"}
curl-3-0 {"k":"v"}
curl-4-0 {"k":"v"}
curl-5-0 {"k":"v"}
