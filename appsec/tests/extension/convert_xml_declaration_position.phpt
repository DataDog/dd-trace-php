--TEST--
XML declarations are allowed only at the start of the document
--DESCRIPTION--
A misplaced declaration stops parsing. The data recorded before it is
still returned with a Partial status, so the status is read from the
debug log and printed before each result.
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_declaration_position.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';

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
    echo $name, ': ', convert_xml_status_json($xml), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_declaration_position.log'); ?>
--EXPECT--
inside-content: Partial {"r":["before"]}
after-whitespace: Empty null
after-comment: Empty null
after-pi: Empty null
after-doctype: Empty null
after-doctype-and-bom: Empty null
duplicate-declaration: Empty null
after-root: Partial {"r":["before"]}
inside-entity: Partial {"r":["before"]}
inside-entity-after-text: Partial {"r":["beforeprefix"]}
inside-nested-entity: Partial {"r":["before"]}
inside-dtd: Empty null
inside-parameter-entity: Empty null
double-bom: Empty null
at-start: Complete {"r":["tail"]}
after-bom: Complete {"r":["tail"]}
no-declaration: Complete {"r":["tail"]}
with-prolog: Complete {"r":["tail"]}
xml-stylesheet-pi: Complete {"r":["before",{"x":["tail"]}]}
dtd-pi-and-comment: Complete {"r":["tail"]}
unused-entity-declaration: Complete {"r":["tail"]}
declaration-in-cdata: Complete {"r":["<?xml version=\"1.0\"?>"]}
