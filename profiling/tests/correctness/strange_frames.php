The purpose of this test is to check that we capture certain frames correctly,
as they are a bit strange compared to regular frames.

<?php

namespace Datadog\Test {
    class Class1 {
        static function method1() {
            $closure = static function () {
                \Datadog\Profiling\trigger_time_sample();
            };

            (new class($closure) {
                function __construct(private \Closure $closure) {}
                function __invoke(...$values) {
                    return ($this->closure)(...$values);
                }
            })();
        }
    }
}
namespace {
    function main() {
        \Datadog\Test\Class1::method1();
        echo "Done.", PHP_EOL;
    }

    main();
}

?>
