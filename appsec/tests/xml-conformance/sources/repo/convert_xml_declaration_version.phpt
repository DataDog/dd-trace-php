--TEST--
XML declarations require version before optional fields
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'encoding-only' => '<?xml encoding="UTF-8"?><r>tail</r>',
    'standalone-only' => '<?xml standalone="yes"?><r>tail</r>',
    'empty-declaration' => '<?xml ?><r>tail</r>',
    'version-after-encoding' => '<?xml encoding="UTF-8" version="1.0"?><r>tail</r>',
    'unquoted-version' => '<?xml version=1.0?><r>tail</r>',
    'bom-without-version' => "\xEF\xBB\xBF" . '<?xml encoding="UTF-8"?><r>tail</r>',
    'no-declaration' => '<r>tail</r>',
    'version-only' => '<?xml version="1.0"?><r>tail</r>',
    'version-with-encoding' => '<?xml version="1.0" encoding="UTF-8"?><r>tail</r>',
    'version-with-standalone' => '<?xml version="1.0" standalone="yes"?><r>tail</r>',
    'single-quotes-and-whitespace' => "<?xml\tversion = '1.0' encoding='UTF-8' standalone='no' ?><r>tail</r>",
    'bom-with-version' => "\xEF\xBB\xBF" . '<?xml version="1.0"?><r>tail</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
encoding-only: null
standalone-only: null
empty-declaration: null
version-after-encoding: null
unquoted-version: null
bom-without-version: null
no-declaration: {"r":["tail"]}
version-only: {"r":["tail"]}
version-with-encoding: {"r":["tail"]}
version-with-standalone: {"r":["tail"]}
single-quotes-and-whitespace: {"r":["tail"]}
bom-with-version: {"r":["tail"]}
