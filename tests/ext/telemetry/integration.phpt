--TEST--
Signal integration telemetry
--ENV--
DD_TRACE_AGENT_URL=file://{PWD}/integration-telemetry.out
DD_TRACE_GENERATE_ROOT_SPAN=0
_DD_LOAD_TEST_INTEGRATIONS=1
--INI--
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

    usleep(100000);
    foreach (file(__DIR__ . '/integration-telemetry.out') as $l) {
        if ($l) {
            $json = json_decode($l, true);
            if ($json["request_type"] == "app-integrations-change") {
                print_r($json["payload"]);
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
                )

        )

)
--CLEAN--
<?php

@unlink(__DIR__ . '/integration-telemetry.out');
