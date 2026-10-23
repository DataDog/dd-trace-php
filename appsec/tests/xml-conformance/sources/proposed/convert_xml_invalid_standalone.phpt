--TEST--
XML standalone declaration accepts only yes or no
--INI--
extension=ddtrace.so
--FILE--
<?php
$xml = '<?xml version="1.0" standalone="maybe"?><r>tail</r>';
echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
?>
--EXPECT--
null
