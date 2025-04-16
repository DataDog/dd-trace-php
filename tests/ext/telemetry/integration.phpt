--TEST--
Signal integration telemetry
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
_DD_LOAD_TEST_INTEGRATIONS=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/integration-telemetry.out"
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        function init(): int
        {
            dd_trace_method("Test", "public_static_method", function() {
                echo "test_access hook" . PHP_EOL;
            });
            return self::LOADED;
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

    for ($i = 0; $i < 100; ++$i) {
        usleep(100000);
        if (file_exists(__DIR__ . '/integration-telemetry.out')) {
            foreach (file(__DIR__ . '/integration-telemetry.out') as $l) {
                if ($l) {
                    $json = json_decode($l, true);
                    $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                    foreach ($batch as $json) {
                        if ($json["request_type"] == "app-integrations-change") {
                            var_dump($json["payload"]);
                            break 3;
                        }
                    }
                }
            }
        }
    }
}

?>
--EXPECT--
PUBLIC STATIC METHOD
test_access hook
array(1) {
  ["integrations"]=>
  array(2) {
    [0]=>
    array(5) {
      ["name"]=>
      string(37) "ddtrace\test\testsandboxedintegration"
      ["enabled"]=>
      bool(true)
      ["version"]=>
      NULL
      ["compatible"]=>
      NULL
      ["auto_enabled"]=>
      NULL
    }
    [1]=>
    array(5) {
      ["name"]=>
      string(4) "logs"
      ["enabled"]=>
      bool(false)
      ["version"]=>
      string(0) ""
      ["compatible"]=>
      NULL
      ["auto_enabled"]=>
      NULL
    }
  }
}
--CLEAN--
<?php

@unlink(__DIR__ . '/integration-telemetry.out');
