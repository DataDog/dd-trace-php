--TEST--
Downstream curl requests: an object as the value of a recorded option skips the transfer
--DESCRIPTION--
An object value of CURLOPT_CUSTOMREQUEST, COOKIE, DEFAULT_PROTOCOL, of an element of CURLOPT_HTTPHEADER or of
an element of an array CURLOPT_POSTFIELDS (CURLFile and CURLStringFile
excepted) is converted by ext/curl, not by the analysis, which would otherwise
record a wrong value: the transfer is skipped as unknown_option. Credential
options are not recorded (G4.6), so an object value of one is no reason to
skip. __toString()
runs exactly once, in ext/curl,
as counted right after curl_setopt(). A scalar value of the same options is
recorded. (An object that cannot be converted at all is
curl_setopt_object_no_tostring.)
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
$helper = ce_helper(array_fill(0, 30, 'ok'));

class S {
    public static $n = 0;
    private $s;
    function __construct($s) { $this->s = $s; }
    function __toString() { self::$n++; return $this->s; }
}

$cases = [
    'CUSTOMREQUEST' => [CURLOPT_CUSTOMREQUEST, function () { return new S('GET'); }],
    'COOKIE' => [CURLOPT_COOKIE, function () { return new S('a=b'); }],
    'USERPWD' => [CURLOPT_USERPWD, function () { return new S('u:p'); }],
    'USERNAME' => [CURLOPT_USERNAME, function () { return new S('u'); }],
    'PASSWORD' => [CURLOPT_PASSWORD, function () { return new S('p'); }],
    'XOAUTH2_BEARER' => [CURLOPT_XOAUTH2_BEARER, function () { return new S('t'); }],
    'DEFAULT_PROTOCOL' => [CURLOPT_DEFAULT_PROTOCOL, function () { return new S('http'); }],
    'HTTPHEADER element' => [CURLOPT_HTTPHEADER, function () { return ['X-A: 1', new S('X-B: 2')]; }],
    'POSTFIELDS element' => [CURLOPT_POSTFIELDS, function () { return ['a' => 'b', 'o' => new S('c')]; }],
    'POSTFIELDS CURLFile' => [CURLOPT_POSTFIELDS, function () { return ['a' => 'b', 'f' => new CURLFile(__FILE__)]; }],
    'COOKIE string' => [CURLOPT_COOKIE, function () { return 'a=b'; }],
];

var_dump(rinit());
foreach ($cases as $label => list($opt, $make)) {
    S::$n = 0;
    $ch = curl_init("$base/json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $r = @curl_setopt($ch, $opt, $make());
    $calls = S::$n; // (ext/curl converts an HTTPHEADER element again at exec)
    curl_exec($ch);
    $d = curl_downstream_dump($ch);
    echo "$label: setopt=", var_export($r, true), " __toString()=", $calls,
        " observed=", var_export($d['observed'], true),
        " reason=", var_export($d['reason'], true), "\n";
}

var_dump(rshutdown());
?>
--EXPECT--
bool(true)
CUSTOMREQUEST: setopt=true __toString()=1 observed=false reason='unknown_option'
COOKIE: setopt=true __toString()=1 observed=false reason='unknown_option'
USERPWD: setopt=true __toString()=1 observed=true reason=NULL
USERNAME: setopt=true __toString()=1 observed=true reason=NULL
PASSWORD: setopt=true __toString()=1 observed=true reason=NULL
XOAUTH2_BEARER: setopt=true __toString()=1 observed=true reason=NULL
DEFAULT_PROTOCOL: setopt=true __toString()=1 observed=false reason='unknown_option'
HTTPHEADER element: setopt=true __toString()=1 observed=false reason='unknown_option'
POSTFIELDS element: setopt=true __toString()=1 observed=false reason='unknown_option'
POSTFIELDS CURLFile: setopt=true __toString()=0 observed=true reason=NULL
COOKIE string: setopt=true __toString()=0 observed=true reason=NULL
bool(true)
