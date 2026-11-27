--TEST--
_convert_xml function (external dtd)
--DESCRIPTION--
When an external DTD is referenced but blocked (for security), parsing continues
and unresolved entities are preserved as literal text in the output.
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_external_dtd.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$entity = <<<XML
<!DOCTYPE note SYSTEM "http://example.com/note.dtd">
<test>&test;</test>
XML;

$content_type = "application/xml";

// External DTD is blocked for security. The &test; entity which would be
// defined in that DTD cannot be resolved, so it appears literally.
list($status, $result) = convert_xml_status($entity, $content_type);
echo 'status: ', $status, "\n";

echo(json_encode($result, JSON_PRETTY_PRINT));
--CLEAN--
<?php @unlink('/tmp/convert_xml_external_dtd.log'); ?>
--EXPECT--
status: Complete
{
    "test": [
        "&test;"
    ]
}
