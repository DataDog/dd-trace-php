--TEST--
XML stops at malformed comments in documents and expanded entities
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'double-hyphen' => '<r>before<!--a--b--><x>tail</x></r>',
    'trailing-hyphen' => '<r>before<!--a---><x>tail</x></r>',
    'unclosed' => '<r>before<!--a',
    'before-root' => '<!--a--b--><r>tail</r>',
    'after-root' => '<r>before</r><!--a--b-->',
    'inside-entity' => '<!DOCTYPE r [<!ENTITY e "<!--a--b--><x>tail</x>">]><r>before&e;after</r>',
    'inside-entity-trailing-hyphen' => '<!DOCTYPE r [<!ENTITY e "<!--a---><x>tail</x>">]><r>before&e;after</r>',
    'inside-entity-after-text' => '<!DOCTYPE r [<!ENTITY e "prefix<!--a--b--><x>tail</x>">]><r>before&e;after</r>',
    'inside-nested-entity' => '<!DOCTYPE r [<!ENTITY e "<!--a--b-->"><!ENTITY outer "&e;">]><r>before&outer;after</r>',
    'inside-dtd' => '<!DOCTYPE r [<!--a--b-->]><r>tail</r>',
    'inside-dtd-trailing-hyphen' => '<!DOCTYPE r [<!--a--->]><r>tail</r>',
    'inside-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<!--a--b-->">%p;]><r>tail</r>',
    'inside-parameter-entity-trailing-hyphen' => '<!DOCTYPE r [<!ENTITY % p "<!--a--->">%p;]><r>tail</r>',
    'inside-parameter-entity-unclosed' => '<!DOCTYPE r [<!ENTITY % p "<!--a">%p;]><r>tail</r>',
    'valid-comments' => '<r>before<!----><!---a--><!--a-b--><x>tail</x></r>',
    'valid-dtd-comment' => '<!DOCTYPE r [<!-- ]>< -->]><r>tail</r>',
    'valid-parameter-entity-comment' => '<!DOCTYPE r [<!ENTITY % p "<!--a-b-->">%p;]><r>tail</r>',
    'valid-general-entity-comment' => '<!DOCTYPE r [<!ENTITY e "<!--a-b-->">]><r>before&e;after</r>',
    'unused-general-entity' => '<!DOCTYPE r [<!ENTITY e "<!--a--b-->">]><r>tail</r>',
    'unused-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<!--a--->">]><r>tail</r>',
    'literal-in-pi' => '<r>before<?p <!--a--b--> ?><x>tail</x></r>',
    'literal-in-cdata' => '<r><![CDATA[<!--a--b-->]]></r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
double-hyphen: {"r":["before"]}
trailing-hyphen: {"r":["before"]}
unclosed: {"r":["before"]}
before-root: null
after-root: {"r":["before"]}
inside-entity: {"r":["before"]}
inside-entity-trailing-hyphen: {"r":["before"]}
inside-entity-after-text: {"r":["beforeprefix"]}
inside-nested-entity: {"r":["before"]}
inside-dtd: null
inside-dtd-trailing-hyphen: null
inside-parameter-entity: null
inside-parameter-entity-trailing-hyphen: null
inside-parameter-entity-unclosed: null
valid-comments: {"r":["before",{"x":["tail"]}]}
valid-dtd-comment: {"r":["tail"]}
valid-parameter-entity-comment: {"r":["tail"]}
valid-general-entity-comment: {"r":["beforeafter"]}
unused-general-entity: {"r":["tail"]}
unused-parameter-entity: {"r":["tail"]}
literal-in-pi: {"r":["before",{"x":["tail"]}]}
literal-in-cdata: {"r":["<!--a--b-->"]}
