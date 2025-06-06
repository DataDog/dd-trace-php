--TEST--
Context switches on execution context switch.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100 || !extension_loaded('ffi') || getenv('PHPUNIT_COVERAGE')) die('skip requires PHP8.1 and FFI'); ?>
--ENV--
OTEL_PHP_FIBERS_ENABLED=1
--FILE--
<?php
use OpenTelemetry\Context\Context;

$vendorDir = getenv("TESTSUITE_VENDOR_DIR") ?: "vendor";
require_once "./tests/OpenTelemetry/$vendorDir/autoload.php";

$key = Context::createKey('-');
$scope = Context::getCurrent()
    ->with($key, 'main')
    ->activate();

$fiber = new Fiber(function() use ($key) {
    $scope = Context::getCurrent()
        ->with($key, 'fiber')
        ->activate();

    echo 'fiber:' . Context::getCurrent()->get($key), PHP_EOL;

    Fiber::suspend();
    echo 'fiber:' . Context::getCurrent()->get($key), PHP_EOL;

    $scope->detach();
});

echo 'main:' . Context::getCurrent()->get($key), PHP_EOL;

$fiber->start();
echo 'main:' . Context::getCurrent()->get($key), PHP_EOL;

$fiber->resume();
echo 'main:' . Context::getCurrent()->get($key), PHP_EOL;

$scope->detach();
?>
--EXPECT--
main:main
fiber:fiber
main:main
fiber:fiber
main:main
