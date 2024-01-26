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

//\datadog\appsec\testing\stop_for_debugger();
$result = datadog\appsec\testing\convert_xml($entity, $content_type);
if (!$result) {
print_r(libxml_get_errors());
}

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECT--
{
    "note": {
        "content": [
            {
                "to": {
                    "content": [
                        "my recipient"
                    ],
                    "attributes": {
                        "attr": "x"
                    }
                }
            },
            {
                "from": {
                    "content": [
                        "Jani"
                    ]
                }
            },
            {
                "tos": {
                    "content": [
                        {
                            "to": {
                                "content": [
                                    "John"
                                ]
                            }
                        },
                        {
                            "to": {
                                "content": [
                                    "Jane"
                                ]
                            }
                        }
                    ]
                }
            },
            {
                "heading": {
                    "content": [
                        "Reminder"
                    ]
                }
            },
            "\n  begin note\n  ",
            {
                "p": {
                    "content": [
                        "Don't forget me this weekend!"
                    ]
                }
            },
            "\n  end note\n  ",
            {
                "br": []
            },
            {
                "br": {
                    "attributes": {
                        "myattr": "attr value"
                    }
                }
            }
        ]
    }
}
