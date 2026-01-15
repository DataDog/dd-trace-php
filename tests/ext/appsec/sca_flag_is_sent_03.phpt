--TEST--
DD_APPSEC_SCA_ENABLED flag is sent to via telemetry with false
--DESCRIPTION--
This configuration is used by the backend to display/charge customers
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
DD_APPSEC_SCA_ENABLED=false
--INI--
datadog.trace.agent_url="file://{PWD}/sca_flag_is_sent_03-telemetry.out"
--FILE_EXTERNAL--
sca_test.inc
--EXPECT--
array(5) {
  ["name"]=>
  string(18) "appsec.sca_enabled"
  ["value"]=>
  string(5) "false"
  ["origin"]=>
  string(7) "env_var"
  ["config_id"]=>
  NULL
  ["seq_id"]=>
  NULL
}
string(4) "Sent"
--CLEAN--
<?php

@unlink(__DIR__ . '/sca_flag_is_sent_03-telemetry.out');
