--TEST--
XML rejects every letter-case combination of the reserved PI target
--INI--
extension=ddtrace.so
--FILE--
<?php
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
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
xml: {"r":["before"]}
xmL: {"r":["before"]}
xMl: {"r":["before"]}
xML: {"r":["before"]}
Xml: {"r":["before"]}
XmL: {"r":["before"]}
XMl: {"r":["before"]}
XML: {"r":["before"]}
before-root: null
after-root: {"r":["before"]}
inside-entity: {"r":["before"]}
inside-entity-after-text: {"r":["beforeprefix"]}
inside-nested-entity: {"r":["before"]}
inside-dtd: null
inside-parameter-entity: null
stylesheet: {"r":["before",{"x":["tail"]}]}
uppercase-prefix: {"r":["before",{"x":["tail"]}]}
longer-name: {"r":["before",{"x":["tail"]}]}
longer-name-in-dtd: {"r":["tail"]}
declaration: {"r":["tail"]}
unused-general-entity: {"r":["tail"]}
unused-parameter-entity: {"r":["tail"]}
literal-in-comment: {"r":["before",{"x":["tail"]}]}
literal-in-cdata: {"r":["<?XML info?>"]}
