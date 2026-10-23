--TEST--
XML content-type parsing recognizes complete charset parameters
--DESCRIPTION--
RFC 9110 section 5.6.6 allows quoted parameter values. Parameter names
and values must be matched in full, and other parameter values must not
be interpreted as charset declarations.
Retain the existing tolerance for whitespace around '='.
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'no charset' => 'application/xml',
    'plain UTF-8' => 'application/xml; charset=UTF-8',
    'quoted UTF-8' => 'application/xml; charset="UTF-8"',
    'mixed case' => 'application/xml; ChArSeT="uTf-8"',
    'escaped UTF-8' => 'application/xml; charset="UTF\\-8"',
    'surrounding whitespace' => "application/xml;\tcharset = \"UTF-8\" \t; note=ok",
    'empty parameters' => 'application/xml; ; charset="UTF-8";',
    'unrelated name' => 'application/xml; xcharset=ascii',
    'unrelated suffix' => 'application/xml; charsetx=ascii',
    'unrelated value' => 'application/xml; note="charset=ascii"',
    'quoted semicolon' => 'application/xml; note=";charset=ascii"; charset="UTF-8"',
    'escaped quote' => 'application/xml; note="a\\";charset=ascii"; charset=UTF-8',
    'escaped backslash' => 'application/xml; note="a\\\\"; charset="UTF-8"',
    'unrelated before UTF-8' => 'application/xml; note="charset=ascii"; charset=UTF-8',
    'unrelated before Latin-1' => 'application/xml; note="charset=utf-8"; charset=ISO-8859-1',
    'Latin-1' => 'application/xml; charset=ISO-8859-1',
    'quoted Latin-1' => 'application/xml; charset="ISO-8859-1"',
    'short charset' => 'application/xml; charset=GBK',
    'UTF-8 prefix' => 'application/xml; charset=UTF-80',
    'quoted UTF-8 prefix' => 'application/xml; charset="UTF-80"',
    'space inside quotes' => 'application/xml; charset="UTF-8 "',
    'empty charset' => 'application/xml; charset=',
    'empty quoted charset' => 'application/xml; charset=""',
    'unclosed quote' => 'application/xml; charset="UTF-8',
    'escaped closing quote' => 'application/xml; charset="UTF-8\\"',
    'junk after quote' => 'application/xml; charset="UTF-8"junk',
];
foreach ($cases as $name => $contentType) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml('<r>payload</r>', $contentType)), "\n";
}
?>
--EXPECT--
no charset: {"r":["payload"]}
plain UTF-8: {"r":["payload"]}
quoted UTF-8: {"r":["payload"]}
mixed case: {"r":["payload"]}
escaped UTF-8: {"r":["payload"]}
surrounding whitespace: {"r":["payload"]}
empty parameters: {"r":["payload"]}
unrelated name: {"r":["payload"]}
unrelated suffix: {"r":["payload"]}
unrelated value: {"r":["payload"]}
quoted semicolon: {"r":["payload"]}
escaped quote: {"r":["payload"]}
escaped backslash: {"r":["payload"]}
unrelated before UTF-8: {"r":["payload"]}
unrelated before Latin-1: null
Latin-1: null
quoted Latin-1: null
short charset: null
UTF-8 prefix: null
quoted UTF-8 prefix: null
space inside quotes: null
empty charset: null
empty quoted charset: null
unclosed quote: null
escaped closing quote: null
junk after quote: null
