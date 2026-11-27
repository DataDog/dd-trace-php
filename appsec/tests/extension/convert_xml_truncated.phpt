--TEST--
_convert_xml function (truncated XML)
--DESCRIPTION--
Tests parsing of truncated/partial XML documents. The parser should recover
gracefully and return whatever structure was successfully parsed.
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_truncated.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
// Test 1: Truncated in the middle of an element
$xml1 = '<root><item>content</item><other>trun';
list($status, $result1) = convert_xml_status($xml1, "text/xml");
echo "Test 1 (truncated mid-element):\n";
echo 'status: ', $status, "\n";
echo json_encode($result1, JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Truncated with unclosed tags
$xml2 = '<root><a><b><c>text</c></b>';
list($status, $result2) = convert_xml_status($xml2, "text/xml");
echo "Test 2 (unclosed tags):\n";
echo 'status: ', $status, "\n";
echo json_encode($result2, JSON_PRETTY_PRINT) . "\n\n";

// Test 3: Truncated in attribute
$xml3 = '<root attr="val';
list($status, $result3) = convert_xml_status($xml3, "text/xml");
echo "Test 3 (truncated in attribute):\n";
echo 'status: ', $status, "\n";
echo json_encode($result3, JSON_PRETTY_PRINT) . "\n\n";

// Test 4: Just an opening tag
$xml4 = '<root>';
list($status, $result4) = convert_xml_status($xml4, "text/xml");
echo "Test 4 (just opening tag):\n";
echo 'status: ', $status, "\n";
echo json_encode($result4, JSON_PRETTY_PRINT) . "\n\n";

// Test 5: Empty input
$xml5 = '';
list($status, $result5) = convert_xml_status($xml5, "text/xml");
echo "Test 5 (empty input):\n";
echo 'status: ', $status, "\n";
var_dump($result5);
--CLEAN--
<?php @unlink('/tmp/convert_xml_truncated.log'); ?>
--EXPECTF--
Test 1 (truncated mid-element):
status: Partial
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
status: Partial
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
status: Partial
{
    "root": []
}

Test 4 (just opening tag):
status: Partial
{
    "root": []
}

Test 5 (empty input):
status: Empty
NULL
