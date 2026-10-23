--TEST--
XML internal entity replacement preserves an initial U+FEFF character
--DESCRIPTION--
XML 1.0 Fifth Edition section 4.5 requires character references in entity
declarations to become replacement text. U+FEFF at the beginning of an
internal entity is character data, not the encoding signature that section
4.3.3 allows at the start of an external parsed entity.
https://www.w3.org/TR/2008/REC-xml-20081126/#intern-replacement
https://www.w3.org/TR/2008/REC-xml-20081126/#charencoding
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'character reference' => '<!DOCTYPE r [<!ENTITY e "&#xFEFF;payload">]><r>&e;</r>',
    'literal character' => "<!DOCTYPE r [<!ENTITY e \"\xEF\xBB\xBFpayload\">]><r>&e;</r>",
    'nested entity' => '<!DOCTYPE r [<!ENTITY e "&#xFEFF;"><!ENTITY a "&e;payload">]><r>&a;</r>',
    'after text' => '<!DOCTYPE r [<!ENTITY e "&#xFEFF;payload">]><r>before&e;</r>',
    'repeated' => '<!DOCTYPE r [<!ENTITY e "&#xFEFF;&#xFEFF;payload">]><r>&e;</r>',
    // Controls: attribute expansion and a direct character reference must
    // deliver the same character as expansion into element content.
    'attribute' => '<!DOCTYPE r [<!ENTITY e "&#xFEFF;payload">]><r a="&e;"/>',
    'direct' => '<r>&#xFEFF;payload</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
character reference: {"r":["\ufeffpayload"]}
literal character: {"r":["\ufeffpayload"]}
nested entity: {"r":["\ufeffpayload"]}
after text: {"r":["before\ufeffpayload"]}
repeated: {"r":["\ufeff\ufeffpayload"]}
attribute: {"r":[{"@a":"\ufeffpayload"}]}
direct: {"r":["\ufeffpayload"]}
