--TEST--
XML parsing stops at a double hyphen inside a comment
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<r>before<!--a--b--><x>tail</x></r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
