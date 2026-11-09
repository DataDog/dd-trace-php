--TEST--
_convert_xml function with namespaced elements and attributes
--FILE--
<?php
$entity = <<<XML
<root xmlns:ns="http://example.com/ns" xmlns:other="http://example.com/other">
  <ns:item ns:id="123" other:type="test">content</ns:item>
  <other:data ns:ref="abc">value</other:data>
</root>
XML;

$content_type = "application/xml";

$result = datadog\appsec\convert_xml($entity, $content_type);
echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECTF--
{
    "root": [
        {
            "ns:item": [
                {
                    "@ns:id": "123",
                    "@other:type": "test"
                },
                "content"
            ]
        },
        {
            "other:data": [
                {
                    "@ns:ref": "abc"
                },
                "value"
            ]
        }
    ]
}
