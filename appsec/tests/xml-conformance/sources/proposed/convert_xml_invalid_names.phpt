--TEST--
XML rejects names outside the XML 1.0 Fifth Edition productions
--DESCRIPTION--
All invalid root names below are rejected by native Expat 2.7.3 and
libxml 2.9.13. The valid controls intentionally include characters which
libxml and XML 1.0 Fifth Edition accept but Expat rejects.
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'digit initial' => '<1>tail</1>',
    'middle dot initial' => '<·a>tail</·a>',
    'combining initial' => "<\u{0300}a>tail</\u{0300}a>",
    'punctuation' => '<a?>tail</a?>',
    'NBSP' => "<a\u{00A0}>tail</a\u{00A0}>",
    'zero width space' => "<a\u{200B}>tail</a\u{200B}>",
    'outside name range' => "<\u{F0000}>tail</\u{F0000}>",
    'valid middle dot' => '<a·>tail</a·>',
    'valid emoji' => '<😀>tail</😀>',
    'valid zero width nonjoiner' => "<a\u{200C}>tail</a\u{200C}>",
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
digit initial: null
middle dot initial: null
combining initial: null
punctuation: null
NBSP: null
zero width space: null
outside name range: null
valid middle dot: {"a\u00b7":["tail"]}
valid emoji: {"\ud83d\ude00":["tail"]}
valid zero width nonjoiner: {"a\u200c":["tail"]}
