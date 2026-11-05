--TEST--
XML internal declarations match application-visible entities and attributes
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_dtd.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
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
    echo convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_dtd.log'); ?>
--EXPECT--
Complete {"r":["payload"]}
Complete {"r":["payload"]}
Complete {"r":["attack"]}
Complete {"r":["before",{"child":[{"@key":"v"},"value"]},"after"]}
Complete {"r":[{"child":[]}," <text>"]}
Complete {"r":[{"@a":"attack","@b":"v"}]}
Complete {"r":[{"@a":"explicit"}]}
Complete {"r":[{"@a":"a b"}]}
Complete {"r":[{"@a":"default"},"payload"]}
Complete {"r":[{"@a":"a b c d"},"x\ny\nz"]}
