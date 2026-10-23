--TEST--
XML conversion keeps the usable prefix before a fatal content error
--DESCRIPTION--
Expat and libxml reject all these complete malformed constructs. Prefix
recovery may retain already parsed content, but must stop before the
following element. This preserves truncation tolerance without consuming
content after a fatal well-formedness error.
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'NUL' => "<r>before\0after<x>tail</x></r>",
    'vertical tab' => "<r>before\vafter<x>tail</x></r>",
    'forbidden FFFF' => "<r>before\u{FFFF}after<x>tail</x></r>",
    'comment double hyphen' => '<r>before<!--a--b--><x>tail</x></r>',
    'CDATA terminator' => '<r>before]]><x>tail</x></r>',
    'declaration in content' => '<r>before<?xml version="1.0"?><x>tail</x></r>',
    'DOCTYPE in content' => '<r>before<!DOCTYPE r [<!ENTITY e "after">]>&e;<x>tail</x></r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
NUL: {"r":["before"]}
vertical tab: {"r":["before"]}
forbidden FFFF: {"r":["before"]}
comment double hyphen: {"r":["before"]}
CDATA terminator: {"r":["before"]}
declaration in content: {"r":["before"]}
DOCTYPE in content: {"r":["before"]}
