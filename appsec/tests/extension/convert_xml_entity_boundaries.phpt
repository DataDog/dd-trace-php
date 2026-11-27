--TEST--
XML entity boundary errors retain the parsed prefix and stop the document
--DESCRIPTION--
XML 1.0 section 4.3.2 requires entities to contain balanced content.
An entity cannot close its containing element or leave elements open for
the document to close. Errors in entity replacements stop outer parsing.
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_entity_boundaries.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$cases = [
    'close root' => '<!DOCTYPE r [<!ENTITY e "</r>">]><r>&e;x</r>',
    'close parent then reopen' => '<!DOCTYPE r [<!ENTITY e "</p><p>">]><r><p>before&e;after</p><tail/></r>',
    'open child' => '<!DOCTYPE r [<!ENTITY e "<a>">]><r>before&e;after<tail/></r>',
    'unfinished tag' => '<!DOCTYPE r [<!ENTITY e "<a">]><r>before&e;after<tail/></r>',
    'unfinished comment' => '<!DOCTYPE r [<!ENTITY e "<!--">]><r>before&e;after<tail/></r>',
    'unfinished PI' => '<!DOCTYPE r [<!ENTITY e "<?p">]><r>before&e;after<tail/></r>',
    'unfinished CDATA' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[">]><r>before&e;after<tail/></r>',
    'nested entity' => '<!DOCTYPE r [<!ENTITY inner "</r>"><!ENTITY e "&inner;<a/>">]><r>&e;after<tail/></r>',
    'balanced entity' => '<!DOCTYPE r [<!ENTITY inner "<a/>x<b>y</b>"><!ENTITY e "&inner;<c/>">]><r>before&e;after</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_entity_boundaries.log'); ?>
--EXPECT--
close root: Partial {"r":[]}
close parent then reopen: Partial {"r":[{"p":["before"]}]}
open child: Partial {"r":["before",{"a":[]}]}
unfinished tag: Partial {"r":["before",{"a":[]}]}
unfinished comment: Partial {"r":["before"]}
unfinished PI: Partial {"r":["before"]}
unfinished CDATA: Partial {"r":["before"]}
nested entity: Partial {"r":[]}
balanced entity: Complete {"r":["before",{"a":[]},"x",{"b":["y"]},{"c":[]},"after"]}
