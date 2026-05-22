--TEST--
FFE Remote Config loads and removes UFC config
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.01
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=ffe/remote_config_lifecycle
--FILE--
<?php
require __DIR__ . "/../remote_config/remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

function put_ffe_config_file($json) {
    $path = "datadog/2/FFE_FLAGS/" . sha1($json) . "/config";
    put_rc_file($path, $json);
    return $path;
}

function wait_for_ffe_config($expected) {
    for ($i = 0; $i < 200; $i++) {
        if (\DDTrace\ffe_has_config() === $expected) {
            return true;
        }
        usleep(100000);
    }
    return false;
}

reset_request_replayer();
show('before', \DDTrace\ffe_has_config());

$config = <<<'JSON'
{
  "createdAt": "2024-01-01T00:00:00Z",
  "environment": {"name": "test"},
  "flags": {
    "string.flag": {
      "key": "string.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "blue": {"key": "blue", "value": "blue"}
      },
      "allocations": [{
        "key": "alloc-string",
        "rules": [],
        "splits": [{"variationKey": "blue", "serialId": 7, "shards": []}],
        "doLog": true
      }]
    }
  }
}
JSON;

$path = put_ffe_config_file($config);
\DDTrace\start_span();

show('loaded', wait_for_ffe_config(true));
show('has_config_after_add', \DDTrace\ffe_has_config());
show('success', \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array()));

$version = \DDTrace\ffe_config_version();
del_rc_file($path);

show('removed', wait_for_ffe_config(false));
show('has_config_after_remove', \DDTrace\ffe_has_config());
show('version_increased', \DDTrace\ffe_config_version() > $version);
?>
--CLEAN--
<?php
require __DIR__ . "/../remote_config/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
before=false
loaded=true
has_config_after_add=true
success={"value_json":"\"blue\"","variant":"blue","allocation_key":"alloc-string","reason":0,"error_code":0,"do_log":true}
removed=true
has_config_after_remove=false
version_increased=true
