--TEST--
XML declaration requires a version
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<?xml encoding="UTF-8"?><r>tail</r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
null
