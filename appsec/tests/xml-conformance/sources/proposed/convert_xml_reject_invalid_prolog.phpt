--TEST--
XML must not recover a root after a complete malformed prolog construct
--DESCRIPTION--
Every input is rejected by native Expat 2.7.3 and libxml 2.9.13 before
either emits a root element. This is distinct from accepting a truncated
root, since there is no usable document prefix before the fatal error.
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'missing version' => '<?xml encoding="UTF-8"?><r>tail</r>',
    'invalid standalone' => '<?xml version="1.0" standalone="maybe"?><r>tail</r>',
    'misplaced declaration' => ' <?xml version="1.0"?><r>tail</r>',
    'duplicate declaration' => '<?xml version="1.0"?><?xml version="1.0"?><r>tail</r>',
    'invalid comment' => '<!--a--b--><r>tail</r>',
    'reserved PI target' => '<?XML test?><r>tail</r>',
    'invalid PI target' => '<?1 test?><r>tail</r>',
    'text before root' => 'before<r>tail</r>',
    'CDATA before root' => '<![CDATA[before]]><r>tail</r>',
    'unmatched end' => '</stray><r>tail</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
missing version: null
invalid standalone: null
misplaced declaration: null
duplicate declaration: null
invalid comment: null
reserved PI target: null
invalid PI target: null
text before root: null
CDATA before root: null
unmatched end: null
