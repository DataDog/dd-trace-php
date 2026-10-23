--TEST--
XML parsing stops at a forbidden literal NUL character
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = "<r>before\0<x>tail</x></r>";
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
