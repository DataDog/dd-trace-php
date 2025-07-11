--TEST--
Reserved OTel attributes that have special meaning
--ENV--
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

function greet($name)
{
    $span = \DDTrace\active_span();
    $span->meta['operation.name'] = 'New.name';
    $span->meta['resource.name'] = 'new.resource';
    $span->meta['span.type'] = 'new.type';
    $span->meta['service.name'] = 'new.service';
    $span->meta['analytics.event'] = 'true';
    return "Hello $name!";
}

\DDTrace\trace_function('greet', function (\DDTrace\SpanData $span) {
    $span->name = 'old.name';
    $span->resource = 'old.resource';
    $span->type = 'old.type';
    $span->service = 'old.service';
    $span->metrics['_dd1.sr.eausr'] = 0;
});

greet('World');

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(11) {
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
    string(8) "new.name"
    ["resource"]=>
    string(12) "new.resource"
    ["service"]=>
    string(11) "new.service"
    ["type"]=>
    string(8) "new.type"
    ["meta"]=>
    array(1) {
      ["_dd.base_service"]=>
      string(27) "test_special_attributes.php"
    }
    ["metrics"]=>
    array(1) {
      ["_dd1.sr.eausr"]=>
      float(1)
    }
  }
}
