--TEST--
DD_APPSEC_SCA_ENABLED flag is sent to via telemetry with default value
--DESCRIPTION--
This configuration is used by the backend to display/charge customers
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
--INI--
datadog.trace.agent_url="file://{PWD}/sca_flag_is_sent_01-telemetry.out"
--FILE_EXTERNAL--
sca_test.inc
--EXPECT--
array(3) {
  ["name"]=>
  string(18) "appsec.sca_enabled"
  ["value"]=>
  string(5) "false"
  ["origin"]=>
  string(7) "Default"
}
string(4) "Sent"
--CLEAN--
<?php

@unlink(__DIR__ . '/sca_flag_is_sent_01-telemetry.out');
