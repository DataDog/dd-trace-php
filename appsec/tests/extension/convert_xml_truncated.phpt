--TEST--
_convert_xml function (truncated XML)
--DESCRIPTION--
Tests parsing of truncated/partial XML documents. The parser should recover
gracefully and return whatever structure was successfully parsed.
--FILE--
<?php
// Test 1: Truncated in the middle of an element
$xml1 = '<root><item>content</item><other>trun';
$result1 = datadog\appsec\convert_xml($xml1, "text/xml");
echo "Test 1 (truncated mid-element):\n";
echo json_encode($result1, JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Truncated with unclosed tags
$xml2 = '<root><a><b><c>text</c></b>';
$result2 = datadog\appsec\convert_xml($xml2, "text/xml");
echo "Test 2 (unclosed tags):\n";
echo json_encode($result2, JSON_PRETTY_PRINT) . "\n\n";

// Test 3: Truncated in attribute
$xml3 = '<root attr="val';
$result3 = datadog\appsec\convert_xml($xml3, "text/xml");
echo "Test 3 (truncated in attribute):\n";
echo json_encode($result3, JSON_PRETTY_PRINT) . "\n\n";

// Test 4: Just an opening tag
$xml4 = '<root>';
$result4 = datadog\appsec\convert_xml($xml4, "text/xml");
echo "Test 4 (just opening tag):\n";
echo json_encode($result4, JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Empty input
$xml5 = '';
$result5 = datadog\appsec\convert_xml($xml5, "text/xml");
echo "Test 5 (empty input):\n";
var_dump($result5);
--EXPECTF--
Test 1 (truncated mid-element):
{
    "root": [
        {
            "item": [
                "content"
            ]
        },
        {
            "other": [
                "trun"
            ]
        }
    ]
}

Test 2 (unclosed tags):
{
    "root": [
        {
            "a": [
                {
                    "b": [
                        {
                            "c": [
                                "text"
                            ]
                        }
                    ]
                }
            ]
        }
    ]
}

Test 3 (truncated in attribute):
{
    "root": []
}

Test 4 (just opening tag):
{
    "root": []
}

Test 5 (empty input):
NULL
