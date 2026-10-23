--TEST--
XML parsing stops at a DOCTYPE inside element content
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<r>before<!DOCTYPE r [<!ENTITY e "after">]>&e;<x>tail</x></r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
