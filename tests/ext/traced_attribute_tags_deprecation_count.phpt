--TEST--
#[DDTrace\Trace] tags: the deprecation is raised once per hooked function (a trait method once per using class), not per call
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: attributes require PHP 8.0'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$deprecations = [];
set_error_handler(function ($errno, $errstr) use (&$deprecations) {
    $deprecations[] = $errstr;
    return true;
}, E_DEPRECATED);

echo "-- trait used by two classes\n";
eval('
trait T {
    #[DDTrace\Trace(tags: ["a" => "b"])]
    function traced() {}
}
class UsesT1 { use T; }
class UsesT2 { use T; }
');
echo count($deprecations), "\n";

echo "-- inherited method\n";
$deprecations = [];
eval('
class Base {
    #[DDTrace\Trace(tags: ["a" => "b"])]
    function traced() {}
}
class Child extends Base {}
');
echo count($deprecations), "\n";

echo "-- calls\n";
$deprecations = [];
(new UsesT1)->traced();
(new UsesT2)->traced();
(new Child)->traced();
(new Child)->traced();
echo count($deprecations), "\n";
?>
--EXPECT--
-- trait used by two classes
3
-- inherited method
1
-- calls
0
