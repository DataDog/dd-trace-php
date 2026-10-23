--TEST--
Parser allocation failure releases scratch/output and allows subsequent calls
--INI--
extension=ddtrace.so
--FILE--
<?php
use function datadog\appsec\testing\body_parser_fail_after;

$cases = [
    ['datadog\appsec\convert_json', ['{"s":"escaped\\nstring","a":[1,{"k":true}]}']],
    ['datadog\appsec\convert_xml', [
        '<!DOCTYPE r [<!ENTITY e "<c>text</c>"><!ATTLIST r a CDATA "default">]><r>&e;</r>',
        'application/xml',
    ]],
];
foreach ($cases as list($convert, $args)) {
    body_parser_fail_after();
    $expected = $convert(...$args);
    $count = body_parser_fail_after();
    if ($count <= 3) {
        throw new Exception('Parser scratch did not use Zend allocation');
    }
    for ($i = 0; $i < $count; ++$i) {
        body_parser_fail_after($i);
        $before = memory_get_usage();
        $result = $convert(...$args);
        if ($result !== null || memory_get_usage() !== $before) {
            throw new Exception("Failure or leak at allocation $i");
        }
        if (body_parser_fail_after() !== $i + 1) {
            throw new Exception('Attempted another allocation after OOM');
        }
        if ($convert(...$args) !== $expected) {
            throw new Exception('Parser did not recover after OOM');
        }
    }
    var_dump(is_array($expected));
}
echo "All allocation points passed\n";
?>
--EXPECT--
bool(true)
bool(true)
All allocation points passed
