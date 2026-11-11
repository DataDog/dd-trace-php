--TEST--
XML parser accepts UTF-8 and US-ASCII and retains only valid data on encoding errors
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_utf8.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';
$cases = [
    '<?xml version="1.0" encoding="UTF-8"?><r a="é">日本語</r>',
    '<?xml version="1.0" encoding="ISO-8859-1"?><r>text</r>',
    '<?xml version="1.0" encoding="US-ASCII"?><r a="x">text</r>',
    '<?xml version="1.0" encoding="us-ascii"?><r>ab<c/>café tail</r>',
    '<?xml version="1.0" encoding="ASCII"?><r>text</r>',
    "<r>ok<x>\xff</x></r>",
    "<r>ok<x>\xc3",
];
foreach ($cases as $xml) {
    echo convert_xml_status_json($xml, 'application/xml', JSON_UNESCAPED_UNICODE), "\n";
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_utf8.log'); ?>
--EXPECT--
Complete {"r":[{"@a":"é"},"日本語"]}
Empty null
Complete {"r":[{"@a":"x"},"text"]}
Partial {"r":["ab",{"c":[]},"caf"]}
Empty null
Partial {"r":["ok",{"x":[]}]}
Partial {"r":["ok",{"x":[]}]}
