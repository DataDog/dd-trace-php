--TEST--
DD_APPSEC_AGENTIC_ONBOARDING flag is sent via telemetry with default (empty) value
--DESCRIPTION--
RFC-1113: telemetry-only marker; always reported, empty with origin=default when unset
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/agentic_onboarding_is_sent_01-telemetry.out"
--FILE_EXTERNAL--
agentic_onboarding_test.inc
--EXPECT--
array(5) {
  ["name"]=>
  string(28) "DD_APPSEC_AGENTIC_ONBOARDING"
  ["value"]=>
  string(0) ""
  ["origin"]=>
  string(7) "default"
  ["config_id"]=>
  NULL
  ["seq_id"]=>
  NULL
}
string(4) "Sent"
--CLEAN--
<?php

@unlink(__DIR__ . '/agentic_onboarding_is_sent_01-telemetry.out');
