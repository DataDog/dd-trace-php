--TEST--
FFE native bridge surfaces the split serial_id (Rust FfeResult -> C reader -> PHP) for APM span enrichment
--FILE--
<?php
function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

// Before any config: an evaluation cannot carry a serial id.
$notReady = \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array());
show('not_ready_serial', $notReady->serialId);

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
        "splits": [{"variationKey": "blue", "serialId": 4242, "shards": []}],
        "doLog": true
      }]
    },
    "no.serial.flag": {
      "key": "no.serial.flag",
      "enabled": true,
      "variationType": "STRING",
      "variations": {
        "green": {"key": "green", "value": "green"}
      },
      "allocations": [{
        "key": "alloc-no-serial",
        "rules": [],
        "splits": [{"variationKey": "green", "shards": []}],
        "doLog": false
      }]
    }
  }
}
JSON;

show('load', \DDTrace\Testing\ffe_load_config($config));

// A split that declares serialId surfaces it as an int on the result object.
$withSerial = \DDTrace\ffe_evaluate('string.flag', 0, 'user-1', array());
show('with_serial_variant', $withSerial->variant);
show('with_serial_id', $withSerial->serialId);
show('with_serial_id_is_int', is_int($withSerial->serialId));

// A split that omits serialId leaves it null (Pattern B: absence != 0). This is
// what lets the PHP accumulator treat the evaluation as a runtime default rather
// than mistaking a 0 sentinel for a real serial id.
$noSerial = \DDTrace\ffe_evaluate('no.serial.flag', 0, 'user-1', array());
show('no_serial_variant', $noSerial->variant);
show('no_serial_id', $noSerial->serialId);

// Errors / unknown flags also carry a null serial id.
$missing = \DDTrace\ffe_evaluate('missing.flag', 0, 'user-1', array());
show('missing_serial', $missing->serialId);
?>
--EXPECT--
not_ready_serial=null
load=true
with_serial_variant="blue"
with_serial_id=4242
with_serial_id_is_int=true
no_serial_variant="green"
no_serial_id=null
missing_serial=null
