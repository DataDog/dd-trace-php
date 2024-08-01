--TEST--
Simple telemetry test
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/simple-telemetry.out"
--FILE--
<?php

DDTrace\start_span();
$root = DDTrace\root_span();

$root->service = 'simple-telemetry-app';
$root->meta['env'] = 'test-env';

DDTrace\close_span();

// At this stage, the service and env are stored to be used by telemetry
dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/simple-telemetry.out')) {
        $batches = [];
        foreach (file(__DIR__ . '/simple-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                array_push($batches, ...($json["request_type"] == "message-batch" ? $json["payload"] : [$json]));
            }
        }
        $found = array_filter($batches, function ($json) {
            return ($json["request_type"] == "app-started" && $json["application"] != "background_sender-php-service") || $json["request_type"] == "app-closing";
        });
        if (count($found) == 2) {
            foreach ($found as $json) {
                $request_type = $json['request_type'];
                var_dump($json["request_type"]);

                if ($request_type == 'app-started') {
                    var_dump($json['application']['service_name']);
                    var_dump($json['application']['env']);
                }
            }
            break;
        }
    }
}

?>
--EXPECT--
string(11) "app-started"
string(20) "simple-telemetry-app"
string(8) "test-env"
string(11) "app-closing"
--CLEAN--
<?php

@unlink(__DIR__ . '/simple-telemetry.out');
