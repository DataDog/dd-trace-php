--TEST--
XML standalone declarations accept only literal yes or no
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'unknown-value' => '<?xml version="1.0" standalone="maybe"?><r>tail</r>',
    'empty-value' => '<?xml version="1.0" standalone=""?><r>tail</r>',
    'uppercase-yes' => '<?xml version="1.0" standalone="YES"?><r>tail</r>',
    'mixed-case-no' => '<?xml version="1.0" standalone="No"?><r>tail</r>',
    'padded-value' => '<?xml version="1.0" standalone=" yes "?><r>tail</r>',
    'character-reference' => '<?xml version="1.0" standalone="&#121;es"?><r>tail</r>',
    'unquoted-value' => '<?xml version="1.0" standalone=yes?><r>tail</r>',
    'missing-value' => '<?xml version="1.0" standalone?><r>tail</r>',
    'omitted' => '<?xml version="1.0"?><r>tail</r>',
    'yes' => '<?xml version="1.0" standalone="yes"?><r>tail</r>',
    'no' => '<?xml version="1.0" standalone="no"?><r>tail</r>',
    'single-quotes-and-whitespace' => "<?xml version='1.0' encoding='UTF-8' standalone = 'yes' ?><r>tail</r>",
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
unknown-value: null
empty-value: null
uppercase-yes: null
mixed-case-no: null
padded-value: null
character-reference: null
unquoted-value: null
missing-value: null
omitted: {"r":["tail"]}
yes: {"r":["tail"]}
no: {"r":["tail"]}
single-quotes-and-whitespace: {"r":["tail"]}
