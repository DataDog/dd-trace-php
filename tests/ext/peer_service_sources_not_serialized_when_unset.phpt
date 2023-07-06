--TEST--
Value of internal span's peerServiceSource is not added to the serialized version of the span when not set
--FILE--
<?php

function test($a) {
    return 'METHOD ' . $a;
}

DDTrace\trace_function("test", function($s, $a, $retval) {
    echo 'HOOK ' . $retval . PHP_EOL;
});

test("arg");

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
HOOK METHOD arg
array(1) {
  [0]=>
  array(9) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["service"]=>
    string(50) "peer_service_sources_not_serialized_when_unset.php"
    ["type"]=>
    string(3) "cli"
  }
}
