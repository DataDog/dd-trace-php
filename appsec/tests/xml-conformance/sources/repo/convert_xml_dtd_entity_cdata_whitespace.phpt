--TEST--
XML entity replacement retains whitespace-only CDATA sections
--DESCRIPTION--
XML 1.0 sections 2.7 and 4.4.2 require the character data in a CDATA section
to be included when parsing an internal entity's replacement text. Dropping
whitespace-only CDATA changes the surrounding text, for example "a b" to "ab".
https://www.w3.org/TR/2008/REC-xml-20081126/#sec-cdata-sect
https://www.w3.org/TR/2008/REC-xml-20081126/#included
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'space' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[ ]]>">]><r>a&e;b</r>',
    'tab' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[&#x9;]]>">]><r>a&e;b</r>',
    'cr' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[&#xD;]]>">]><r>a&e;b</r>',
    'nested' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[ ]]>"><!ENTITY a "a&e;b">]><r>&a;</r>',
    'direct' => '<r>a<![CDATA[ ]]>b</r>',
    'literal entity' => '<!DOCTYPE r [<!ENTITY e " ">]><r>a&e;b</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
space: {"r":["a b"]}
tab: {"r":["a\tb"]}
cr: {"r":["a\rb"]}
nested: {"r":["a b"]}
direct: {"r":["a b"]}
literal entity: {"r":["a b"]}
