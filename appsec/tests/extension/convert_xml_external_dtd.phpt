--TEST--
_convert_xml function (external dtd)
--DESCRIPTION--
When an external DTD is referenced but blocked (for security), parsing continues
and unresolved entities are preserved as literal text in the output.
--FILE--
<?php
$entity = <<<XML
<!DOCTYPE note SYSTEM "http://example.com/note.dtd">
<test>&test;</test>
XML;

$content_type = "application/xml";

// External DTD is blocked for security. The &test; entity which would be
// defined in that DTD cannot be resolved, so it appears literally.
$result = datadog\appsec\convert_xml($entity, $content_type);

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECT--
{
    "test": [
        "&test;"
    ]
}
