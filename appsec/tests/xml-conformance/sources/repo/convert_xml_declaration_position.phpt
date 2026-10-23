--TEST--
XML declarations are allowed only at the start of the document
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'inside-content' => '<r>before<?xml version="1.0"?><x>tail</x></r>',
    'after-whitespace' => ' <?xml version="1.0"?><r>tail</r>',
    'after-comment' => '<!-- comment --><?xml version="1.0"?><r>tail</r>',
    'after-pi' => '<?p info?><?xml version="1.0"?><r>tail</r>',
    'after-doctype' => '<!DOCTYPE r><?xml version="1.0"?><r>tail</r>',
    'after-doctype-and-bom' => '<!DOCTYPE r>' . "\xEF\xBB\xBF" . '<?xml version="1.0"?><r>tail</r>',
    'duplicate-declaration' => '<?xml version="1.0"?><?xml version="1.0"?><r>tail</r>',
    'after-root' => '<r>before</r><?xml version="1.0"?>',
    'inside-entity' => '<!DOCTYPE r [<!ENTITY e "<?xml version=\'1.0\'?><x>tail</x>">]><r>before&e;after</r>',
    'inside-entity-after-text' => '<!DOCTYPE r [<!ENTITY e "prefix<?xml version=\'1.0\'?><x>tail</x>">]><r>before&e;after</r>',
    'inside-nested-entity' => '<!DOCTYPE r [<!ENTITY e "<?xml version=\'1.0\'?>"><!ENTITY outer "&e;"><!ENTITY unused "ignored">]><r>before&outer;after</r>',
    'inside-dtd' => '<!DOCTYPE r [<?xml version="1.0"?>]><r>tail</r>',
    'inside-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<?xml version=\'1.0\'?>">%p;]><r>tail</r>',
    'double-bom' => "\xEF\xBB\xBF\xEF\xBB\xBF" . '<?xml version="1.0"?><r>tail</r>',
    'at-start' => '<?xml version="1.0"?><r>tail</r>',
    'after-bom' => "\xEF\xBB\xBF" . '<?xml version="1.0"?><r>tail</r>',
    'no-declaration' => ' <!-- comment --><?p info?><r>tail</r>',
    'with-prolog' => '<?xml version="1.0"?><!-- comment --><?p info?><!DOCTYPE r><r>tail</r>',
    'xml-stylesheet-pi' => '<r>before<?xml-stylesheet href="style"?><x>tail</x></r>',
    'dtd-pi-and-comment' => '<!DOCTYPE r [<?xml-stylesheet href="style"?><!-- <?xml version="1.0"?> -->]><r>tail</r>',
    'unused-entity-declaration' => '<!DOCTYPE r [<!ENTITY e "<?xml version=\'1.0\'?>">]><r>tail</r>',
    'declaration-in-cdata' => '<r><![CDATA[<?xml version="1.0"?>]]></r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
inside-content: {"r":["before"]}
after-whitespace: null
after-comment: null
after-pi: null
after-doctype: null
after-doctype-and-bom: null
duplicate-declaration: null
after-root: {"r":["before"]}
inside-entity: {"r":["before"]}
inside-entity-after-text: {"r":["beforeprefix"]}
inside-nested-entity: {"r":["before"]}
inside-dtd: null
inside-parameter-entity: null
double-bom: null
at-start: {"r":["tail"]}
after-bom: {"r":["tail"]}
no-declaration: {"r":["tail"]}
with-prolog: {"r":["tail"]}
xml-stylesheet-pi: {"r":["before",{"x":["tail"]}]}
dtd-pi-and-comment: {"r":["tail"]}
unused-entity-declaration: {"r":["tail"]}
declaration-in-cdata: {"r":["<?xml version=\"1.0\"?>"]}
