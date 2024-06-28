--TEST--
Replacing the return value of a generator instantiation in the begin hook
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: not implemented yet on PHP 7'); ?>
--FILE--
<?php

class Response {
    public function getIterator() {
        $fooData = [1, 2, 3];
        foreach ($fooData as $value) {
            yield $value;
        }
    }
}

\DDTrace\install_hook(
    'Response::getIterator',
    function (\DDTrace\HookData $hook) {
        echo "Prehook Response::getIterator\n";
        $barData = [4, 5, 6];
        $barGenerator = function() use ($barData) {
            echo "Starting generator\n";
            foreach ($barData as $value) {
                echo "Yielding $value\n";
                yield $value;
            }
        };
        echo "Overriding return value\n";
        var_dump($hook->overrideReturnValue($barGenerator()));
    }
);

$response = new Response();

function foo($response) {
    var_dump(iterator_to_array($response->getIterator()));
}

foo($response);

?>
--EXPECT--
Prehook Response::getIterator
Overriding return value
bool(true)
Starting generator
Yielding 4
Yielding 5
Yielding 6
array(3) {
  [0]=>
  int(4)
  [1]=>
  int(5)
  [2]=>
  int(6)
}
