--TEST--
Extended internal classes are hookable
--FILE--
<?php

DDTrace\hook_method('Countable', 'count', function () {
    echo " Countable";
});

DDTrace\hook_method('ArrayObject', 'count', function () {
    echo " ArrayObject";
});

// Runtime resolution
if (true) {
    class A extends ArrayObject {
    }
}

echo "Normal:";
(new ArrayObject([1]))->count();
echo "\nExtended:";
(new A)->count();
//echo "\nAnonymous:";
//(new class extends ArrayObject {})->count();

dd_untrace('count', 'Countable');
dd_untrace('count', 'ArrayObject');

echo "\nNormal: Nohook";
(new ArrayObject([1]))->count();
echo "\nExtended: Nohook";
(new A)->count();
//echo "\nAnonymous: Nohook";
//(new class extends ArrayObject {})->count();

$anon = new class extends ArrayObject {};
DDTrace\hook_method('Countable', 'count', function () {
    echo " Countable";
});
DDTrace\hook_method('ArrayObject', 'count', function () {
    echo " ArrayObject";
});
echo "\nNormal:";
(new ArrayObject([1]))->count();
echo "\nExtended:";
(new A)->count();
// echo "\nAnonymous:";
// $anon->count();

?>
--EXPECT--
Normal: ArrayObject Countable
Extended: ArrayObject Countable
Normal: Nohook
Extended: Nohook
Normal: ArrayObject Countable
Extended: ArrayObject Countable
