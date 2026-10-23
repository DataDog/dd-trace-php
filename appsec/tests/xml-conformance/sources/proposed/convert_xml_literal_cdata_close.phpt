--TEST--
XML parsing stops at a literal CDATA closing delimiter in character data
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<r>before]]><x>tail</x></r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
