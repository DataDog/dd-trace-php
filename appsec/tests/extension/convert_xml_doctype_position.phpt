--TEST--
XML permits one DOCTYPE before the document element
--DESCRIPTION--
A misplaced DOCTYPE stops parsing. The data recorded before it is still
returned with a Partial status, so the status is read from the debug log
and printed before each result.
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_doctype_position.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';

$cases = [
    'inside-content' => '<r>before<!DOCTYPE r [<!ENTITY e "after">]>&e;<x>tail</x></r>',
    'inside-child' => '<r><x>before<!DOCTYPE r>after</x><tail/></r>',
    'after-root' => '<r>before</r><!DOCTYPE r>',
    'after-empty-root' => '<r/><!DOCTYPE r>',
    'repeated-doctype' => '<!DOCTYPE r><!DOCTYPE r [<!ENTITY e "after">]><r>&e;</r>',
    'repeated-after-comment' => '<!DOCTYPE r><!-- comment --><?p info?><!DOCTYPE r><r/>',
    'repeated-after-bom' => '<!DOCTYPE r>' . "\xEF\xBB\xBF" . '<!DOCTYPE r><r/>',
    'inside-entity' => '<!DOCTYPE r [<!ENTITY e "<!DOCTYPE r><x>tail</x>">]><r>before&e;after</r>',
    'inside-entity-after-text' => '<!DOCTYPE r [<!ENTITY e "prefix<!DOCTYPE r><x>tail</x>">]><r>before&e;after</r>',
    'inside-nested-entity' => '<!DOCTYPE r [<!ENTITY e "<!DOCTYPE r>"><!ENTITY outer "&e;">]><r>before&outer;after</r>',
    'inside-dtd' => '<!DOCTYPE r [<!DOCTYPE nested>]><r>tail</r>',
    'inside-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<!DOCTYPE nested>">%p;]><r>tail</r>',
    'lowercase-in-content' => '<r>before<!doctype r><x>tail</x></r>',
    'double-bom' => "\xEF\xBB\xBF\xEF\xBB\xBF" . '<!DOCTYPE r><r>tail</r>',
    'before-root' => '<!DOCTYPE r [<!ENTITY e "value">]><r>&e;</r>',
    'after-bom' => "\xEF\xBB\xBF" . '<!DOCTYPE r><r>tail</r>',
    'with-prolog' => '<?xml version="1.0"?> <!-- comment --><?p info?><!DOCTYPE r><r>tail</r>',
    'different-root-name' => '<!DOCTYPE other><r/>',
    'doctype-in-comment-and-pi' => '<!-- <!DOCTYPE ignored> --><?p <!DOCTYPE ignored>?><!DOCTYPE r><r>tail</r>',
    'unused-general-entity' => '<!DOCTYPE r [<!ENTITY e "<!DOCTYPE nested>">]><r>tail</r>',
    'unused-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<!DOCTYPE nested>">]><r>tail</r>',
    'doctype-in-cdata' => '<r><![CDATA[<!DOCTYPE r>]]></r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', convert_xml_status_json($xml), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_doctype_position.log'); ?>
--EXPECT--
inside-content: Partial {"r":["before"]}
inside-child: Partial {"r":[{"x":["before"]}]}
after-root: Partial {"r":["before"]}
after-empty-root: Partial {"r":[]}
repeated-doctype: Empty null
repeated-after-comment: Empty null
repeated-after-bom: Empty null
inside-entity: Partial {"r":["before"]}
inside-entity-after-text: Partial {"r":["beforeprefix"]}
inside-nested-entity: Partial {"r":["before"]}
inside-dtd: Empty null
inside-parameter-entity: Empty null
lowercase-in-content: Partial {"r":["before"]}
double-bom: Empty null
before-root: Complete {"r":["value"]}
after-bom: Complete {"r":["tail"]}
with-prolog: Complete {"r":["tail"]}
different-root-name: Complete {"r":[]}
doctype-in-comment-and-pi: Complete {"r":["tail"]}
unused-general-entity: Complete {"r":["tail"]}
unused-parameter-entity: Complete {"r":["tail"]}
doctype-in-cdata: Complete {"r":["<!DOCTYPE r>"]}
