--TEST--
Signal integration telemetry
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
_DD_LOAD_TEST_INTEGRATIONS=1
DD_TRACE_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url=file://{PWD}/integration-telemetry.out
ddtrace.request_init_hook={PWD}/../sandbox/deferred_loading_helper.php
--FILE--
<?php

namespace DDTrace\Test
{
    use DDTrace\Integrations\Integration;

    class TestSandboxedIntegration extends Integration
    {
        function init()
        {
            dd_trace_method("Test", "public_static_method", function() {
                echo "test_access hook" . PHP_EOL;
            });
            return Integration::LOADED;
        }
    }
}

namespace
{
    class Test
    {
        public static function public_static_method()
        {
            echo "PUBLIC STATIC METHOD\n";
        }
    }

    Test::public_static_method();

    dd_trace_internal_fn("finalize_telemetry");

    usleep(300000);
    foreach (file(__DIR__ . '/integration-telemetry.out') as $l) {
        if ($l) {
            $json = json_decode($l, true);
            $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
            foreach ($batch as $json) {
                if ($json["request_type"] == "app-integrations-change") {
                    print_r($json["payload"]);
                }
            }
        }
    }
}

?>
--EXPECT--
PUBLIC STATIC METHOD
test_access hook
Array
(
    [integrations] => Array
        (
            [0] => Array
                (
                    [name] => ddtrace\test\testsandboxedintegration
                    [enabled] => 1
                )

        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/integration-telemetry.out');
