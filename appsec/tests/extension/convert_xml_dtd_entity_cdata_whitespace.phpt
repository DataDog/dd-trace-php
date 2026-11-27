--TEST--
XML entity replacement retains whitespace-only CDATA sections
--DESCRIPTION--
XML 1.0 sections 2.7 and 4.4.2 require the character data in a CDATA section
to be included when parsing an internal entity's replacement text. Dropping
whitespace-only CDATA changes the surrounding text, for example "a b" to "ab".
https://www.w3.org/TR/2008/REC-xml-20081126/#sec-cdata-sect
https://www.w3.org/TR/2008/REC-xml-20081126/#included
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_dtd_entity_cdata_whitespace.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$cases = [
    'space' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[ ]]>">]><r>a&e;b</r>',
    'tab' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[&#x9;]]>">]><r>a&e;b</r>',
    'cr' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[&#xD;]]>">]><r>a&e;b</r>',
    'nested' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[ ]]>"><!ENTITY a "a&e;b">]><r>&a;</r>',
    'direct' => '<r>a<![CDATA[ ]]>b</r>',
    'literal entity' => '<!DOCTYPE r [<!ENTITY e " ">]><r>a&e;b</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_dtd_entity_cdata_whitespace.log'); ?>
--EXPECT--
space: Complete {"r":["a b"]}
tab: Complete {"r":["a\tb"]}
cr: Complete {"r":["a\rb"]}
nested: Complete {"r":["a b"]}
direct: Complete {"r":["a b"]}
literal entity: Complete {"r":["a b"]}
