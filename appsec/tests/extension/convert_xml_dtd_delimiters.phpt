--TEST--
XML DTD delimiters inside comments, processing instructions and literals
--DESCRIPTION--
XML 1.0 Fifth Edition sections 2.5, 2.6 and 2.8 permit angle brackets
inside DTD comments, processing instructions and quoted entity values.
They must not change where the document type declaration ends.
https://www.w3.org/TR/2008/REC-xml-20081126/#NT-doctypedecl
https://www.w3.org/TR/2008/REC-xml-20081126/#NT-Comment
https://www.w3.org/TR/2008/REC-xml-20081126/#NT-PI
https://www.w3.org/TR/2008/REC-xml-20081126/#NT-EntityValue
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'comment lt' => '<!-- < -->',
    'comment gt' => '<!-- > -->',
    'pi lt' => '<?p < ?>',
    'pi gt' => '<?p > ?>',
    'entity lt' => '<!ENTITY unused "<">',
    'entity gt' => '<!ENTITY unused ">">',
    'default gt' => '<!ATTLIST r a CDATA ">">',
];
foreach ($cases as $name => $declaration) {
    $xml = '<!DOCTYPE r [' . $declaration
        . '<!ENTITY e "payload">]><r>&e;</r>';
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
$cases = [
    'entity value' => '<!DOCTYPE r [<!ENTITY e "a>b>c">]><r>&e;</r>',
    'default value' => '<!DOCTYPE r [<!ATTLIST r a CDATA "a>b>c">]><r/>',
    'balanced comment' => '<!DOCTYPE r [<!-- <> --><!ENTITY e "payload">]><r>&e;</r>',
    'balanced pi' => '<!DOCTYPE r [<?p <> ?><!ENTITY e "payload">]><r>&e;</r>',
    'document bom' => "\xEF\xBB\xBF"
        . '<!DOCTYPE r [<!-- < --><!ENTITY e "payload">]><r>&e;</r>',
    'prolog' => '<?xml version="1.0"?><!-- <!DOCTYPE ignored --><?p <!DOCTYPE ignored ?>'
        . "\n<!DOCTYPE r [<!-- > --><!ENTITY e \"payload\">]><r>&e;</r>",
    'external id' => '<!DOCTYPE r SYSTEM "urn:example:a<b"><r>payload</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
comment lt: {"r":["payload"]}
comment gt: {"r":["payload"]}
pi lt: {"r":["payload"]}
pi gt: {"r":["payload"]}
entity lt: {"r":["payload"]}
entity gt: {"r":["payload"]}
default gt: {"r":[{"@a":">"},"payload"]}
entity value: {"r":["a>b>c"]}
default value: {"r":[{"@a":"a>b>c"}]}
balanced comment: {"r":["payload"]}
balanced pi: {"r":["payload"]}
document bom: {"r":["payload"]}
prolog: {"r":["payload"]}
external id: {"r":["payload"]}
