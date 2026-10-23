--TEST--
XML internal declarations match application-visible entities and attributes
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    '<!DOCTYPE r [<!-- <!ENTITY e "safe"> --><!ENTITY e "payload">]><r>&e;</r>',
    '<!DOCTYPE r [<!ENTITY unused \'<!ENTITY e "safe">\'><!ENTITY e "payload">]><r>&e;</r>',
    '<!DOCTYPE r [<!ENTITY a "&b;"><!ENTITY b "attack">]><r>&a;</r>',
    '<!DOCTYPE r [<!ENTITY e "<child key=\'v\'>value</child>">]><r>before&e;after</r>',
    '<!DOCTYPE r [<!ENTITY e "&#60;child/> &lt;text&gt;">]><r>&e;</r>',
    '<!DOCTYPE r [<!-- <!ATTLIST r a CDATA "safe"> --><!ATTLIST r a CDATA "&e;" b CDATA #FIXED "v"><!ENTITY e "attack">]><r/>',
    '<!DOCTYPE r [<!ATTLIST r a CDATA "default"><!ATTLIST r a CDATA "ignored">]><r a="explicit"/>',
    '<!DOCTYPE r [<!ATTLIST r a NMTOKENS #IMPLIED>]><r a=" a  b "/>',
    '<!DOCTYPE r [<!ENTITY % d "<!ENTITY e \'payload\'><!ATTLIST r a CDATA \'default\'>">%d;]><r>&e;</r>',
    "<r a=\"a\tb\nc\rd\">x\r\ny\rz</r>",
];
foreach ($cases as $xml) {
    echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
{"r":["payload"]}
{"r":["payload"]}
{"r":["attack"]}
{"r":["before",{"child":[{"@key":"v"},"value"]},"after"]}
{"r":[{"child":[]}," <text>"]}
{"r":[{"@a":"attack","@b":"v"}]}
{"r":[{"@a":"explicit"}]}
{"r":[{"@a":"a b"}]}
{"r":[{"@a":"default"},"payload"]}
{"r":[{"@a":"a b c d"},"x\ny\nz"]}
