--TEST--
Call root_span_get_meta() without ddtrace loaded
--FILE--
<?php

namespace ddtrace;

use function \datadog\appsec\testing\root_span_get_meta;

echo "no function defined:\n";
var_dump(root_span_get_meta());

$val = 'val';
if (true) {
    function root_span() {
        return $GLOBALS['val'];
    }
}
echo "function defined but doesn't return an object:\n";
var_dump(root_span_get_meta());

$val = new \DatePeriod('R4/2012-07-01T00:00:00Z/P7D');
echo "function defined returns an object without meta property:\n";
var_dump(root_span_get_meta());


echo "function defined returns an object with a meta property that is not an array:\n";
$val = new \stdclass;
$val->meta = 'foobar';
var_dump(root_span_get_meta());


echo "root_span_get_meta() separates the array:\n";
$val = new \stdclass;
$val->meta = array('env' => 'staging');
//\datadog\appsec\testing\stop_for_debugger();
debug_zval_dump(root_span_get_meta());

?>
--EXPECTF--
no function defined:
NULL
function defined but doesn't return an object:

Warning: datadog\appsec\testing\root_span_get_meta(): [ddappsec] Expecting an object from \ddtrace\root_span in %s on line %d
NULL
function defined returns an object without meta property:

Warning: datadog\appsec\testing\root_span_get_meta(): [ddappsec] %s in %s on line %d
NULL
function defined returns an object with a meta property that is not an array:

Warning: datadog\appsec\testing\root_span_get_meta(): [ddappsec] The property 'meta' on span is no array in %s on line %d
NULL
root_span_get_meta() separates the array:
array(1) refcount(2){
  ["env"]=>
  string(7) "staging" refcount(2)
}
