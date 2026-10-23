--TEST--
XML rejects literal CDATA closing delimiters in character data
--INI--
extension=ddtrace.so
--FILE--
<?php
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
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
literal: {"r":["before"]}
extra-brackets: {"r":["before]]"]}
text-start: {"r":[]}
after-reference: {"r":["before&"]}
before-root: null
after-root: {"r":["before"]}
inside-entity: {"r":["beforeprefix"]}
inside-entity-after-markup: {"r":["before",{"x":[]},"prefix"]}
inside-nested-entity: {"r":["beforeprefix"]}
numeric-reference-in-entity: {"r":["beforeprefix"]}
predefined-reference: {"r":["]]>"]}
numeric-reference: {"r":["]]>"]}
escaped-bracket: {"r":["]]>"]}
entity-boundary: {"r":["]]>"]}
empty-entity-boundary: {"r":["]]>"]}
predefined-reference-in-entity: {"r":["]]>"]}
unused-entity: {"r":["tail"]}
comment-boundary: {"r":["]]>"]}
pi-boundary: {"r":["]]>"]}
cdata-boundary: {"r":["]]>"]}
literal-in-comment: {"r":["before",{"x":["tail"]}]}
literal-in-pi: {"r":["before",{"x":["tail"]}]}
literal-in-attribute: {"r":[{"@a":"]]>"}]}
literal-in-default: {"r":[{"@a":"]]>"}]}
