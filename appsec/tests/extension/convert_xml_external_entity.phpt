--TEST--
_convert_xml function (external entity)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70100) {
    die('skip &test; entity is not resolved in this PHP version');
}
?>
--FILE--
<?php
$entity = <<<XML
<!DOCTYPE test [
    <!ENTITY test "test">
    <!ENTITY myentity SYSTEM "/etc/passwd">
]>
<test>&test;&myentity;</test>
XML;

$content_type = "application/xml";

$result = datadog\appsec\testing\convert_xml($entity, $content_type);

echo(json_encode($result, JSON_PRETTY_PRINT));
--EXPECT--
{
    "test": [
        "test"
    ]
}
