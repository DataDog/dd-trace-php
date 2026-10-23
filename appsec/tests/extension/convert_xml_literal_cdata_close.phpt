--TEST--
XML rejects literal CDATA closing delimiters in character data
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_literal_cdata_close.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$cases = [
    'literal' => '<r>before]]><x>tail</x></r>',
    'extra-brackets' => '<r>before]]]]><x>tail</x></r>',
    'text-start' => '<r>]]><x>tail</x></r>',
    'after-reference' => '<r>before&amp;]]><x>tail</x></r>',
    'before-root' => ']]><r>tail</r>',
    'after-root' => '<r>before</r>]]>',
    'inside-entity' => '<!DOCTYPE r [<!ENTITY e "prefix]]>after">]><r>before&e;<x>tail</x></r>',
    'inside-entity-after-markup' => '<!DOCTYPE r [<!ENTITY e "<x/>prefix]]>after">]><r>before&e;<tail/></r>',
    'inside-nested-entity' => '<!DOCTYPE r [<!ENTITY e "prefix]]>after"><!ENTITY outer "&e;tail">]><r>before&outer;<x>tail</x></r>',
    'numeric-reference-in-entity' => '<!DOCTYPE r [<!ENTITY e "prefix]]&#62;after">]><r>before&e;<x>tail</x></r>',
    'predefined-reference' => '<r>]]&gt;</r>',
    'numeric-reference' => '<r>]]&#62;</r>',
    'escaped-bracket' => '<r>]&#93;></r>',
    'entity-boundary' => '<!DOCTYPE r [<!ENTITY e "]">]><r>]&e;></r>',
    'empty-entity-boundary' => '<!DOCTYPE r [<!ENTITY e "">]><r>]]&e;></r>',
    'predefined-reference-in-entity' => '<!DOCTYPE r [<!ENTITY e "]]&gt;">]><r>&e;</r>',
    'unused-entity' => '<!DOCTYPE r [<!ENTITY e "]]>unused">]><r>tail</r>',
    'comment-boundary' => '<r>]]<!-- c -->></r>',
    'pi-boundary' => '<r>]]<?p ?>></r>',
    'cdata-boundary' => '<r>]]<![CDATA[>]]></r>',
    'literal-in-comment' => '<r>before<!-- ]]> --><x>tail</x></r>',
    'literal-in-pi' => '<r>before<?p ]]> ?><x>tail</x></r>',
    'literal-in-attribute' => '<r a="]]>"/>',
    'literal-in-default' => '<!DOCTYPE r [<!ATTLIST r a CDATA "]]>">]><r/>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_literal_cdata_close.log'); ?>
--EXPECT--
literal: Partial {"r":["before"]}
extra-brackets: Partial {"r":["before]]"]}
text-start: Partial {"r":[]}
after-reference: Partial {"r":["before&"]}
before-root: Empty null
after-root: Partial {"r":["before"]}
inside-entity: Partial {"r":["beforeprefix"]}
inside-entity-after-markup: Partial {"r":["before",{"x":[]},"prefix"]}
inside-nested-entity: Partial {"r":["beforeprefix"]}
numeric-reference-in-entity: Partial {"r":["beforeprefix"]}
predefined-reference: Complete {"r":["]]>"]}
numeric-reference: Complete {"r":["]]>"]}
escaped-bracket: Complete {"r":["]]>"]}
entity-boundary: Complete {"r":["]]>"]}
empty-entity-boundary: Complete {"r":["]]>"]}
predefined-reference-in-entity: Complete {"r":["]]>"]}
unused-entity: Complete {"r":["tail"]}
comment-boundary: Complete {"r":["]]>"]}
pi-boundary: Complete {"r":["]]>"]}
cdata-boundary: Complete {"r":["]]>"]}
literal-in-comment: Complete {"r":["before",{"x":["tail"]}]}
literal-in-pi: Complete {"r":["before",{"x":["tail"]}]}
literal-in-attribute: Complete {"r":[{"@a":"]]>"}]}
literal-in-default: Complete {"r":[{"@a":"]]>"}]}
