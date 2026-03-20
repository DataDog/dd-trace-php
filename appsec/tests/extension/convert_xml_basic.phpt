--TEST--
_convert_xml function
--FILE--
<?php
$entity = <<<XML
<note>
  <to attr="x">my recipient</to>
  <from>Jani</from>
  <tos>
    <to>John</to>
    <to>Jane</to>
  </tos>
  <heading>Reminder</heading>
  begin note
  <p>Don't forget me this weekend!</p>
  end note
  <br/>
  <br myattr="attr value"/>
</note>
XML;

$content_type = "application/xml";

$result = datadog\appsec\convert_xml($entity, $content_type);
if (!$result) {
print_r(libxml_get_errors());
}

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECTF--
{
    "note": [
        {
            "to": [
                {
                    "@attr": "x"
                },
                "my recipient"
            ]
        },
        {
            "from": [
                "Jani"
            ]
        },
        {
            "tos": [
                {
                    "to": [
                        "John"
                    ]
                },
                {
                    "to": [
                        "Jane"
                    ]
                }
            ]
        },
        {
            "heading": [
                "Reminder"
            ]
        },
        "\n  begin note\n  ",
        {
            "p": [
                "Don't forget me this weekend!"
            ]
        },
        "\n  end note\n  ",
        {
            "br": []
        },
        {
            "br": [
                {
                    "@myattr": "attr value"
                }
            ]
        }
    ]
}
