--TEST--
An unbalanced XML entity cannot replace the root array with a scalar
--INI--
extension=ddtrace.so
--FILE--
<?php
// XML 1.0 section 4.3.2: parsed entities must have balanced content.
// Keep the usable root prefix when the replacement tries to close it.
$xml = '<!DOCTYPE r [<!ENTITY e "</r>">]><r>&e;x</r>';
$result = datadog\appsec\convert_xml($xml, 'application/xml');
echo gettype($result), "\n";
echo json_encode($result), "\n";
?>
--EXPECT--
array
{"r":[]}
