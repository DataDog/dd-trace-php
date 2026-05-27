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
    "numeric.attribute.flag": {
      "key": "numeric.attribute.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "numeric-key": {"key": "numeric-key", "value": "numeric-attribute-name"}
      },
      "allocations": [{
        "key": "alloc-numeric-attribute",
        "rules": [{
          "conditions": [{
            "attribute": "1234",
            "operator": "ONE_OF",
            "value": ["numeric-match"]
          }]
        }],
        "splits": [{"variationKey": "numeric-key", "shards": []}],
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
show('object_success_value', json_decode($object->valueJson, true));
show('object_success_metadata', array(
    'variant' => $object->variant,
    'allocation_key' => $object->allocationKey,
    'reason' => $object->reason,
    'error_code' => $object->errorCode,
    'do_log' => $object->doLog,
));
show('numeric_attribute_key', \DDTrace\ffe_evaluate('numeric.attribute.flag', 0, 'user-1', array(
    '1234' => 'numeric-match',
)));
show('empty_targeting_key', \DDTrace\ffe_evaluate('empty.targeting.shard.flag', 0, '', array()));
show('missing', \DDTrace\ffe_evaluate('missing.flag', 0, 'user-1', array()));
show('type_mismatch', \DDTrace\ffe_evaluate('string.flag', 3, 'user-1', array()));
show('parse_error', \DDTrace\ffe_evaluate('bad.flag', 0, 'user-1', array()));
?>
--EXPECT--
has_config_before=false
provider_not_ready={"valueJson":"null","variant":null,"allocationKey":null,"reason":5,"errorCode":6,"doLog":false,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
load=true
has_config_after=true
success={"valueJson":"\"blue\"","variant":"blue","allocationKey":"alloc-string","reason":0,"errorCode":0,"doLog":true,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
object_success_value={"enabled":true,"threshold":2}
object_success_metadata={"variant":"json-a","allocation_key":"alloc-json","reason":0,"error_code":0,"do_log":true}
numeric_attribute_key={"valueJson":"\"numeric-attribute-name\"","variant":"numeric-key","allocationKey":"alloc-numeric-attribute","reason":2,"errorCode":0,"doLog":true,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
empty_targeting_key={"valueJson":"\"empty-targeting-key\"","variant":"empty-target","allocationKey":"alloc-empty-targeting-key","reason":3,"errorCode":0,"doLog":true,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
missing={"valueJson":"null","variant":null,"allocationKey":null,"reason":1,"errorCode":3,"doLog":false,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
type_mismatch={"valueJson":"null","variant":null,"allocationKey":null,"reason":5,"errorCode":1,"doLog":false,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
parse_error={"valueJson":"null","variant":null,"allocationKey":null,"reason":5,"errorCode":2,"doLog":false,"providerState":[],"errorMessage":null,"hasConfig":null,"configVersion":null}
