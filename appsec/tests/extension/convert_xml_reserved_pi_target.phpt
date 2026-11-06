--TEST--
XML rejects every letter-case combination of the reserved PI target
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_reserved_pi_target.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$cases = [];
foreach (['xml', 'xmL', 'xMl', 'xML', 'Xml', 'XmL', 'XMl', 'XML'] as $target) {
    $cases[$target] = "<r>before<?$target info?><x>tail</x></r>";
}
$cases += [
    'before-root' => '<?XML info?><r>tail</r>',
    'after-root' => '<r>before</r><?XML info?>',
    'inside-entity' => '<!DOCTYPE r [<!ENTITY e "<?XML info?><x>tail</x>">]><r>before&e;after</r>',
    'inside-entity-after-text' => '<!DOCTYPE r [<!ENTITY e "prefix<?Xml info?><x>tail</x>">]><r>before&e;after</r>',
    'inside-nested-entity' => '<!DOCTYPE r [<!ENTITY e "<?xML info?>"><!ENTITY outer "&e;">]><r>before&outer;after</r>',
    'inside-dtd' => '<!DOCTYPE r [<?XmL info?>]><r>tail</r>',
    'inside-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<?XML info?>">%p;]><r>tail</r>',
    'stylesheet' => '<r>before<?xml-stylesheet info?><x>tail</x></r>',
    'uppercase-prefix' => '<r>before<?XML-stylesheet info?><x>tail</x></r>',
    'longer-name' => '<r>before<?xmlfoo info?><x>tail</x></r>',
    'longer-name-in-dtd' => '<!DOCTYPE r [<?XML-stylesheet info?>]><r>tail</r>',
    'declaration' => '<?xml version="1.0"?><r>tail</r>',
    'unused-general-entity' => '<!DOCTYPE r [<!ENTITY e "<?XML info?>">]><r>tail</r>',
    'unused-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<?XML info?>">]><r>tail</r>',
    'literal-in-comment' => '<r>before<!-- <?XML info?> --><x>tail</x></r>',
    'literal-in-cdata' => '<r><![CDATA[<?XML info?>]]></r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', convert_xml_status_json($xml, 'application/xml'), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_reserved_pi_target.log'); ?>
--EXPECT--
xml: Partial {"r":["before"]}
xmL: Partial {"r":["before"]}
xMl: Partial {"r":["before"]}
xML: Partial {"r":["before"]}
Xml: Partial {"r":["before"]}
XmL: Partial {"r":["before"]}
XMl: Partial {"r":["before"]}
XML: Partial {"r":["before"]}
before-root: Empty null
after-root: Partial {"r":["before"]}
inside-entity: Partial {"r":["before"]}
inside-entity-after-text: Partial {"r":["beforeprefix"]}
inside-nested-entity: Partial {"r":["before"]}
inside-dtd: Empty null
inside-parameter-entity: Empty null
stylesheet: Complete {"r":["before",{"x":["tail"]}]}
uppercase-prefix: Complete {"r":["before",{"x":["tail"]}]}
longer-name: Complete {"r":["before",{"x":["tail"]}]}
longer-name-in-dtd: Complete {"r":["tail"]}
declaration: Complete {"r":["tail"]}
unused-general-entity: Complete {"r":["tail"]}
unused-parameter-entity: Complete {"r":["tail"]}
literal-in-comment: Complete {"r":["before",{"x":["tail"]}]}
literal-in-cdata: Complete {"r":["<?XML info?>"]}
