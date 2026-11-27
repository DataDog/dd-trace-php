--TEST--
Downstream curl requests: the options curl_setopt() records are values
--DESCRIPTION--
The recorded options are strings, numbers, flags and the lines of
CURLOPT_HTTPHEADER and the text fields of an array CURLOPT_POSTFIELDS:
CURLOPT_HEADER, the presence of CURLOPT_READFUNCTION and CURLOPT_INFILE and an
app-owned CURLOPT_PREREQFUNCTION (8.4+) are recorded too, CURLOPT_CUSTOMREQUEST
is recorded verbatim (an empty string is an empty method, not unset), and no
object passed to curl_setopt() is kept alive. G4.6: credentials, VERBOSE,
HEADER_OUT, DEBUGFUNCTION and the write options are not recorded at all, and
an object value of a credential option does not make the handle unknown.
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
$helper = ce_helper([]);
var_dump(rinit());
$ch = curl_init('http://127.0.0.1:1/');
curl_setopt($ch, CURLOPT_USERPWD, 'user:pass');
curl_setopt($ch, CURLOPT_USERNAME, 'name');
curl_setopt($ch, CURLOPT_PASSWORD, 'secret');
curl_setopt($ch, CURLOPT_XOAUTH2_BEARER, 'token');
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $d) { return strlen($d); });
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $d) { return strlen($d); });
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'pAtCh');
curl_setopt($ch, CURLOPT_COOKIE, 'a=b');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_DEFAULT_PROTOCOL, 'https');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-A: 1', 2, 'X-B: 3']);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['a' => 'b', 'n' => 5]);
if (PHP_VERSION_ID >= 80400) {
    curl_setopt($ch, CURLOPT_PREREQFUNCTION, function () { return 0; });
}
$o = curl_downstream_dump($ch)['options'];
$keys = ['url', 'default_protocol', 'customrequest', 'cookie', 'followlocation', 'httpheader', 'postfields', 'header',
    'readfunction', 'infile', 'prereqfunction'];
var_export(array_intersect_key($o, array_flip($keys)));
echo "\n";
// the exact set of recorded fields: nothing else (no credentials, no debug
// or write options) is kept
var_export(array_keys($o));
echo "\n";
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, '');
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_READFUNCTION, function () { return ''; });
curl_setopt($ch, CURLOPT_INFILE, fopen(__FILE__, 'r'));
$o = curl_downstream_dump($ch)['options'];
var_export([$o['customrequest'], $o['header'], $o['readfunction'], $o['infile']]);
echo "\n";
curl_setopt($ch, CURLOPT_READFUNCTION, null);
$o = curl_downstream_dump($ch)['options'];
var_export([$o['readfunction'], $o['infile']]);
echo "\n";
// an object value of a credential option is not recorded, nor makes the
// handle unknown
class Cred { function __toString() { return 'x:y'; } }
curl_setopt($ch, CURLOPT_USERPWD, new Cred);
var_dump(curl_downstream_dump($ch)['reason']);
var_dump(rshutdown());
?>
--EXPECTF--
bool(true)
array (
  'url' => 'http://127.0.0.1:1/',
  'default_protocol' => 'https',
  'customrequest' => 'pAtCh',
  'httpheader' => 
  array (
    0 => 'X-A: 1',
    1 => '2',
    2 => 'X-B: 3',
  ),
  'cookie' => 'a=b',
  'postfields' => 
  array (
    'a' => 'b',
    'n' => 5,
  ),
  'header' => true,
  'readfunction' => false,
  'infile' => false,
  'prereqfunction' => %s,
  'followlocation' => true,
)
array (
  0 => 'url',
  1 => 'default_protocol',
  2 => 'method',
  3 => 'customrequest',
  4 => 'httpheader',
  5 => 'cookie',
  6 => 'postfields',
  7 => 'header',
  8 => 'readfunction',
  9 => 'infile',
  10 => 'prereqfunction',
  11 => 'followlocation',
)
array (
  0 => '',
  1 => false,
  2 => true,
  3 => true,
)
array (
  0 => false,
  1 => true,
)
NULL
bool(true)
