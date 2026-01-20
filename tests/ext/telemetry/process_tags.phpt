--TEST--
Test process_tags in application field
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
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/process-tags-telemetry.out"
--FILE--
<?php

DDTrace\start_span();

include __DIR__ . '/vendor/autoload.php';

DDTrace\close_span();

dd_trace_serialize_closed_spans();

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 300; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/process-tags-telemetry.out')) {
        foreach (file(__DIR__ . '/process-tags-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                var_dump($json["application"]["process_tags"]);
                break 2;
            }
        }
    }
}

?>
--EXPECTF--
Included
string(%d) "entrypoint.basedir:telemetry,entrypoint.name:process_tags,entrypoint.type:script,entrypoint.workdir:%s,runtime.sapi:cli"
--CLEAN--
<?php

@unlink(__DIR__ . '/process-tags-telemetry.out');
