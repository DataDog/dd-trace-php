--TEST--
Exception replay locals do not contain unassigned variables (GH #4382)
--ENV--
DD_EXCEPTION_REPLAY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
--FILE--
<?php

function inner() {
    $before = 'before';
    $e = new RuntimeException('test');
    $after = 'after';
    return $e;
}

function outer() {
    $outerBefore = 1;
    $e = inner();
    $outerAfter = 2;
    return $e;
}

function check($name, $arr) {
    $iterated = 0;
    foreach ($arr as $_) {
        ++$iterated;
    }
    echo "$name: count=", count($arr), " iterated=$iterated keys=", implode(",", array_keys($arr)), "\n";
    var_dump(unserialize(serialize($arr)) === $arr);
}

$e = outer();
$locals = new ReflectionProperty(Exception::class, 'locals');
@$locals->setAccessible(true);
check("locals", $locals->getValue($e));
$trace = new ReflectionProperty(Exception::class, 'trace');
@$trace->setAccessible(true);
check("frame locals", $trace->getValue($e)[0]["locals"]);

?>
--EXPECT--
locals: count=1 iterated=1 keys=before
bool(true)
frame locals: count=1 iterated=1 keys=outerBefore
bool(true)
