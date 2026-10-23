--TEST--
XML parsing stops at a declaration inside element content
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<r>before<?xml version="1.0"?><x>tail</x></r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
