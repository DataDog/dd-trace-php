--TEST--
_convert_xml function (external dtd)
--FILE--
<?php
$entity = <<<XML
<!DOCTYPE note SYSTEM "http://example.com/note.dtd">
<test>&test;</test>
XML;

$content_type = "application/xml";

// unfortunately, this fails (entity not resolved)
// PHP does not provide a way around it
$result = datadog\appsec\testing\convert_xml($entity, $content_type);

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECT--
null
