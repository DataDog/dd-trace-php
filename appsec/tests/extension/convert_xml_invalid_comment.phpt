--TEST--
XML stops at malformed comments in documents and expanded entities
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_invalid_comment.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
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
    echo $name, ': ', convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_invalid_comment.log'); ?>
--EXPECT--
double-hyphen: Partial {"r":["before"]}
trailing-hyphen: Partial {"r":["before"]}
unclosed: Partial {"r":["before"]}
before-root: Empty null
after-root: Partial {"r":["before"]}
inside-entity: Partial {"r":["before"]}
inside-entity-trailing-hyphen: Partial {"r":["before"]}
inside-entity-after-text: Partial {"r":["beforeprefix"]}
inside-nested-entity: Partial {"r":["before"]}
inside-dtd: Empty null
inside-dtd-trailing-hyphen: Empty null
inside-parameter-entity: Empty null
inside-parameter-entity-trailing-hyphen: Empty null
inside-parameter-entity-unclosed: Empty null
valid-comments: Complete {"r":["before",{"x":["tail"]}]}
valid-dtd-comment: Complete {"r":["tail"]}
valid-parameter-entity-comment: Complete {"r":["tail"]}
valid-general-entity-comment: Complete {"r":["beforeafter"]}
unused-general-entity: Complete {"r":["tail"]}
unused-parameter-entity: Complete {"r":["tail"]}
literal-in-pi: Complete {"r":["before",{"x":["tail"]}]}
literal-in-cdata: Complete {"r":["<!--a--b-->"]}
