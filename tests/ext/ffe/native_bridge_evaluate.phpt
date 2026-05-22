--TEST--
FFE native bridge evaluates through libdatadog
--FILE--
<?php
function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

show('has_config_before', \DDTrace\ffe_has_config());
show('provider_not_ready', \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array()));

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
    },
    "object.flag": {
      "key": "object.flag",
      "enabled": true,
      "variationType": "JSON",
      "variations": {
        "json-a": {"key": "json-a", "value": {"enabled": true, "threshold": 2}}
      },
      "allocations": [{
        "key": "alloc-json",
        "rules": [],
        "splits": [{"variationKey": "json-a", "serialId": 8, "shards": []}],
        "doLog": true
      }]
    },
    "empty.targeting.shard.flag": {
      "key": "empty.targeting.shard.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "empty-target": {"key": "empty-target", "value": "empty-targeting-key"}
      },
      "allocations": [{
        "key": "alloc-empty-targeting-key",
        "rules": [],
        "splits": [{
          "variationKey": "empty-target",
          "shards": [{
            "salt": "empty-targeting-key-regression",
            "totalShards": 10000,
            "ranges": [{"start": 8022, "end": 8023}]
          }]
        }],
        "doLog": true
      }]
    },
    "bad.flag": {
      "key": "bad.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "bad": {"key": "bad", "value": 1}
      },
      "allocations": [{
        "key": "alloc-bad",
        "rules": [],
        "splits": [{"variationKey": "bad", "shards": []}]
      }]
    }
  }
}
JSON;

show('load', \DDTrace\Testing\ffe_load_config($config));
show('has_config_after', \DDTrace\ffe_has_config());
show('success', \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array(
    'country' => 'US',
    'age' => 42,
    'ignored' => array('drop'),
)));
$object = \DDTrace\ffe_evaluate('object.flag', 4, 'user-1', array());
show('object_success_value', json_decode($object['value_json'], true));
show('object_success_metadata', array(
    'variant' => $object['variant'],
    'allocation_key' => $object['allocation_key'],
    'reason' => $object['reason'],
    'error_code' => $object['error_code'],
    'do_log' => $object['do_log'],
));
show('empty_targeting_key', \DDTrace\ffe_evaluate('empty.targeting.shard.flag', 0, '', array()));
show('missing', \DDTrace\ffe_evaluate('missing.flag', 0, 'user-1', array()));
show('type_mismatch', \DDTrace\ffe_evaluate('string.flag', 3, 'user-1', array()));
show('parse_error', \DDTrace\ffe_evaluate('bad.flag', 0, 'user-1', array()));
?>
--EXPECT--
has_config_before=false
provider_not_ready={"value_json":"null","variant":null,"allocation_key":null,"reason":5,"error_code":6,"do_log":false}
load=true
has_config_after=true
success={"value_json":"\"blue\"","variant":"blue","allocation_key":"alloc-string","reason":0,"error_code":0,"do_log":true}
object_success_value={"enabled":true,"threshold":2}
object_success_metadata={"variant":"json-a","allocation_key":"alloc-json","reason":0,"error_code":0,"do_log":true}
empty_targeting_key={"value_json":"\"empty-targeting-key\"","variant":"empty-target","allocation_key":"alloc-empty-targeting-key","reason":3,"error_code":0,"do_log":true}
missing={"value_json":"null","variant":null,"allocation_key":null,"reason":1,"error_code":3,"do_log":false}
type_mismatch={"value_json":"null","variant":null,"allocation_key":null,"reason":5,"error_code":1,"do_log":false}
parse_error={"value_json":"null","variant":null,"allocation_key":null,"reason":5,"error_code":2,"do_log":false}
