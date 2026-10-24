--TEST--
XML parser accepts UTF-8 and retains only valid data on encoding errors
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    '<?xml version="1.0" encoding="UTF-8"?><r a="é">日本語</r>',
    '<?xml version="1.0" encoding="ISO-8859-1"?><r>text</r>',
    "<r>ok<x>\xff</x></r>",
    "<r>ok<x>\xc3",
];
foreach ($cases as $xml) {
    echo json_encode(datadog\appsec\convert_xml($xml, 'application/xml'), JSON_UNESCAPED_UNICODE), "\n";
}
?>
--EXPECT--
{"r":[{"@a":"é"},"日本語"]}
null
{"r":["ok",{"x":[]}]}
{"r":["ok",{"x":[]}]}
