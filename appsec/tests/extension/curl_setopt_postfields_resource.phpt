--TEST--
Downstream curl requests: a resource in a POSTFIELDS array is recorded as the text ext/curl sends
--DESCRIPTION--
Only values are kept (G4.10): the field value (or, from PHP 8.2, the element
of a nested array) that is a resource is recorded as "Resource id #n", which is what
ext/curl sends for it, and not as a reference to the app's resource. The
stream is closed when the app drops its last reference to it, not when the
handle goes.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\curl_downstream_dump;
require __DIR__ . '/inc/curl_downstream.php';

class Wrapper {
    public $context;
    function stream_open($path, $mode, $options, &$opened) { return true; }
    function stream_close() { echo "stream closed\n"; }
}
stream_wrapper_register('cdres', 'Wrapper');

$ch = curl_init("http://127.0.0.1:1/");
$fp = fopen('cdres://x', 'r');
$id = (int)$fp;
@curl_setopt($ch, CURLOPT_POSTFIELDS, ['a' => $fp, 'b' => ['x', $fp], 'c' => 'd']);
$d = curl_downstream_dump($ch);
$fields = $d['options']['postfields'];
echo 'a: ', $fields['a'] === "Resource id #$id" ? 'text' : var_export($fields['a'], true), "\n";
// (below PHP 8.2 the nested array is the flat text "Array", with no element)
$b = PHP_VERSION_ID < 80200 ? 'Array' : ['x', "Resource id #$id"];
echo 'b: ', $fields['b'] === $b ? 'text' : var_export($fields['b'], true), "\n";
echo 'c: ', $fields['c'], "\n";
unset($fp);
echo "app reference dropped\n";
cd_close($ch);
echo "done\n";
?>
--EXPECT--
a: text
b: text
c: d
stream closed
app reference dropped
done
