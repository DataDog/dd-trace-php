--TEST--
XML processing instructions reject the reserved XML target
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<r>before<?XML info?><x>tail</x></r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
{"r":["before"]}
