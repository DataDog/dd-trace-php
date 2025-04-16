--TEST--
'spans_created' internal metric
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
datadog.trace.agent_url="file://{PWD}/metrics-spans_created-telemetry.out"
zend.assertions=1
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        function init(): int
        {
            dd_trace_method("Test", "create_span_from_anomymous_source", function() {
            });

            dd_trace_method("Test", "create_span_from_named_integration", function(\DDTrace\SpanData $span, array $args) {
                $span->meta['component'] = $args[0];
            });

            dd_trace_method("Test", "create_span_with_flag", function(\DDTrace\SpanData $span, array $args) {
                \DDTrace\Internal\add_span_flag($span, $args[0]);
            });

            return self::LOADED;
        }
    }
}

namespace
{
    class Test
    {
        // Must be called to trigger the call of TestSandboxedIntegration::init()
        public static function public_static_method()
        {
            echo __METHOD__."\n";
        }

        public static function create_span_from_anomymous_source()
        {
            echo __METHOD__."\n";
        }

        public static function create_span_from_named_integration($integrationName)
        {
            echo __METHOD__."({$integrationName})\n";
        }

        public static function create_span_with_flag()
        {
            echo __METHOD__."\n";
        }
    }

    // Don't create a span
    Test::public_static_method();

    Test::create_span_from_anomymous_source();
    Test::create_span_from_anomymous_source();

    Test::create_span_from_named_integration('testintegration');
    Test::create_span_from_named_integration('testintegration');
    Test::create_span_from_named_integration('testintegration');
    Test::create_span_from_named_integration('foo');

    Test::create_span_with_flag(\DDTRACE\Internal\SPAN_FLAG_OPENTELEMETRY);

    dd_trace_internal_fn("finalize_telemetry");

    for ($i = 0; $i < 100; ++$i) {
        usleep(100000);
        if (file_exists(__DIR__ . '/metrics-spans_created-telemetry.out')) {
            foreach (file(__DIR__ . '/metrics-spans_created-telemetry.out') as $l) {
                if ($l) {
                    $json = json_decode($l, true);
                    $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                    foreach ($batch as $json) {
                        if ($json["request_type"] == "generate-metrics") {
                            $series = [];
                            foreach ($json['payload']['series'] as $serie) {
                                if ($serie['metric'] !== 'spans_created') {
                                  continue;
                                }
                                $key = $serie['namespace'].$serie['metric'].implode(',', $serie['tags']);
                                $series[$key] = $serie;
                            };
                            ksort($series);
                            var_dump(array_values($series));

                            break 3;
                        }
                    }
                }
            }
        }
    }
}

?>
--EXPECTF--
Test::public_static_method
Test::create_span_from_anomymous_source
Test::create_span_from_anomymous_source
Test::create_span_from_named_integration(testintegration)
Test::create_span_from_named_integration(testintegration)
Test::create_span_from_named_integration(testintegration)
Test::create_span_from_named_integration(foo)
Test::create_span_with_flag
array(4) {
  [0]=>
  array(7) {
    ["namespace"]=>
    string(7) "tracers"
    ["metric"]=>
    string(13) "spans_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(2)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(24) "integration_name:datadog"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
  [1]=>
  array(7) {
    ["namespace"]=>
    string(7) "tracers"
    ["metric"]=>
    string(13) "spans_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(1)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(20) "integration_name:foo"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
  [2]=>
  array(7) {
    ["namespace"]=>
    string(7) "tracers"
    ["metric"]=>
    string(13) "spans_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(1)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(21) "integration_name:otel"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
  [3]=>
  array(7) {
    ["namespace"]=>
    string(7) "tracers"
    ["metric"]=>
    string(13) "spans_created"
    ["points"]=>
    array(1) {
      [0]=>
      array(2) {
        [0]=>
        int(%d)
        [1]=>
        float(3)
      }
    }
    ["tags"]=>
    array(1) {
      [0]=>
      string(32) "integration_name:testintegration"
    }
    ["common"]=>
    bool(true)
    ["type"]=>
    string(5) "count"
    ["interval"]=>
    int(10)
  }
}
--CLEAN--
<?php

@unlink(__DIR__ . '/metrics-spans_created-telemetry.out');
