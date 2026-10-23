--TEST--
XML content-type parsing recognizes complete charset parameters
--DESCRIPTION--
RFC 9110 section 5.6.6 allows quoted parameter values. Parameter names
and values must be matched in full, and other parameter values must not
be interpreted as charset declarations.
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'no charset' => 'application/xml',
    'plain UTF-8' => 'application/xml; charset=UTF-8',
    'quoted UTF-8' => 'application/xml; charset="UTF-8"',
    'quoted lowercase' => 'application/xml; charset="utf-8"',
    'unrelated name' => 'application/xml; xcharset=ascii',
    'unrelated value' => 'application/xml; note="charset=ascii"',
    'unrelated before UTF-8' => 'application/xml; note="charset=ascii"; charset=UTF-8',
    'unrelated before Latin-1' => 'application/xml; note="charset=utf-8"; charset=ISO-8859-1',
    'Latin-1' => 'application/xml; charset=ISO-8859-1',
];
foreach ($cases as $name => $contentType) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml('<r>payload</r>', $contentType)), "\n";
}
?>
--EXPECT--
no charset: {"r":["payload"]}
plain UTF-8: {"r":["payload"]}
quoted UTF-8: {"r":["payload"]}
quoted lowercase: {"r":["payload"]}
unrelated name: {"r":["payload"]}
unrelated value: {"r":["payload"]}
unrelated before UTF-8: {"r":["payload"]}
unrelated before Latin-1: null
Latin-1: null
