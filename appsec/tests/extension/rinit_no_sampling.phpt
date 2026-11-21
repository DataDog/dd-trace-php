--TEST--
A zero API security sample delay is sent to the helper as such
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_SAMPLE_DELAY=0.0
--FILE--
<?php
use function datadog\appsec\testing\appsec_session_config;

var_dump(appsec_session_config()['schema_extraction']);
?>
--EXPECTF--
array(2) {
  ["enabled"]=>
  bool(true)
  ["sampling_period"]=>
  float(0)
}
