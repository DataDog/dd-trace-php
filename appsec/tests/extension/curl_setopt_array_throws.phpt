--TEST--
Downstream curl requests: curl_setopt_array() stops at an element that throws
--DESCRIPTION--
G4.12: the loop stops at the first element that leaves an exception pending, as
well as at one that returns false. Here the second of three elements is a
Stringable URL whose __toString() throws: the first is recorded, the third is
neither applied nor recorded.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID < 70400) die('skip PHP 7.4+: before, the exception is a fatal error');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

class Throwing {
    public static $n = 0;
    function __toString() { self::$n++; throw new Exception('boom'); }
}

$ch = curl_init();
try {
    $r = curl_setopt_array($ch, [
        CURLOPT_COOKIE => 'c=1',
        CURLOPT_URL => new Throwing,
        CURLOPT_HTTPHEADER => ['X-A: 1'],
    ]);
    echo "no exception\n";
} catch (Exception $e) {
    echo "threw: ", $e->getMessage(), "\n";
}
echo "__toString() calls: ", Throwing::$n, "\n";
cd_options($ch);
?>
--EXPECT--
threw: boom
__toString() calls: 1
  url: NULL
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: NULL
  cookie: 'c=1'
  postfields: NULL
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
