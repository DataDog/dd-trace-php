--TEST--
FFE evaluation metrics use native recorder
--ENV--
DD_METRICS_OTEL_ENABLED=true
--FILE--
<?php
function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

show('native_recorder_exists', function_exists('DDTrace\\Internal\\record_ffe_evaluation_metric'));
show('native_flush_exists', function_exists('DDTrace\\Internal\\flush_ffe_evaluation_metrics'));
show('old_metrics_forwarder_exists', function_exists('DDTrace\\send_ffe_metrics'));
show('old_exposure_forwarder_exists', function_exists('DDTrace\\send_ffe_exposures'));
show('recorded', \DDTrace\Internal\record_ffe_evaluation_metric(
    'string.flag',
    'blue',
    'SPLIT',
    null,
    'allocation-a'
));

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
        "splits": [{"variationKey": "blue", "serialId": 7, "shards": []}]
      }]
    }
  }
}
JSON;

show('load', \DDTrace\Testing\ffe_load_config($config));
show('evaluation_without_native_metric', \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array(), false));
show('missing_flag_without_native_metric', \DDTrace\ffe_evaluate('missing.flag', 0, 'user-1', array(), false));
?>
--EXPECT--
native_recorder_exists=true
native_flush_exists=true
old_metrics_forwarder_exists=false
old_exposure_forwarder_exists=false
recorded=true
load=true
evaluation_without_native_metric={"valueJson":"\"blue\"","variant":"blue","allocationKey":"alloc-string","reason":0,"errorCode":0,"doLog":true,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
missing_flag_without_native_metric={"valueJson":"null","variant":null,"allocationKey":null,"reason":5,"errorCode":3,"doLog":false,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
