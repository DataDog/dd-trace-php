--TEST--
FFE logging to a failing sink preserves readiness, evaluation and teardown
--SKIPIF--
<?php if (!file_exists('/dev/full')) die('skip requires /dev/full'); ?>
--INI--
datadog.trace.debug=1
datadog.trace.log_file=/dev/full
error_log=/dev/full
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
var_dump(DDTrace\Testing\ffe_load_config('{"createdAt":"2024-01-01T00:00:00Z","environment":{"name":"test"},"flags":{}}'));
var_dump(DDTrace\ffe_config_version() > 0);
$result = DDTrace\ffe_evaluate('missing', DDTrace\FFE_STRING, 'user', array('number' => INF));
var_dump($result->errorCode !== 0);
var_dump(DDTrace\Testing\flush_ffe_exposures());
echo "completed\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
completed
