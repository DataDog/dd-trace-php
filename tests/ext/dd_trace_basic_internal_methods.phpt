--TEST--
dd_trace() basic functionality (internal methods)
--FILE--
<?php
date_default_timezone_set('UTC');

dd_trace('DateTime', 'format', function () {
    echo 'DateTime.format' . PHP_EOL;
    return dd_trace_forward_call();
});

dd_trace('DateTime', 'setTime', function () {
    echo 'DateTime.setTime' . PHP_EOL;
    return dd_trace_forward_call();
});

$dt = new DateTime('2019-12-23');
$dt->setTime(9, 5);
for ($i = 0; $i < 10; $i++) {
    $dt->format('r');
}
$dt->setTime(9, 6);
$dt->setTime(9, 7);
?>
--EXPECT--
DateTime.setTime
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.setTime
DateTime.setTime
