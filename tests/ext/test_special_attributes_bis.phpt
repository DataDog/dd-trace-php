--TEST--
Reserved OTel attributes that have special meaning
--ENV--
DD_SERVICE_MAPPING=new.service:mapped.service
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

function greet($name)
{
    $span = \DDTrace\active_span();
    $span->meta['operation.name'] = 'New.name';
    $span->meta['resource.name'] = 'new.resource';
    $span->meta['span.type'] = 'new.type';
    $span->meta['service.name'] = 'new.service';
    return "Hello $name!";
}

// The prehook sets the properties first, so the later $meta writes override them
\DDTrace\trace_function('greet', ['prehook' => function (\DDTrace\SpanData $span) {
    $span->name = 'old.name';
    $span->resource = 'old.resource';
    $span->type = 'old.type';
    $span->service = 'old.service';
}]);

greet('World');

var_dump(dd_clean_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(9) {
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(8) "new.name"
    ["resource"]=>
    string(12) "new.resource"
    ["service"]=>
    string(14) "mapped.service"
    ["type"]=>
    string(8) "new.type"
    ["attributes"]=>
    array(2) {
      ["_dd.svc_src"]=>
      string(1) "m"
      ["_dd.base_service"]=>
      string(31) "test_special_attributes_bis.php"
    }
  }
}
