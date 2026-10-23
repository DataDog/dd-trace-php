--TEST--
XML permits one DOCTYPE before the document element
--INI--
extension=ddtrace.so
--FILE--
<?php
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
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
inside-content: {"r":["before"]}
inside-child: {"r":[{"x":["before"]}]}
after-root: {"r":["before"]}
after-empty-root: {"r":[]}
repeated-doctype: null
repeated-after-comment: null
repeated-after-bom: null
inside-entity: {"r":["before"]}
inside-entity-after-text: {"r":["beforeprefix"]}
inside-nested-entity: {"r":["before"]}
inside-dtd: null
inside-parameter-entity: null
lowercase-in-content: {"r":["before"]}
double-bom: null
before-root: {"r":["value"]}
after-bom: {"r":["tail"]}
with-prolog: {"r":["tail"]}
different-root-name: {"r":[]}
doctype-in-comment-and-pi: {"r":["tail"]}
unused-general-entity: {"r":["tail"]}
unused-parameter-entity: {"r":["tail"]}
doctype-in-cdata: {"r":["<!DOCTYPE r>"]}
