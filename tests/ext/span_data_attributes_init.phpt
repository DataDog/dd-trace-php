--TEST--
Directly constructed span/stack objects expose an initialized attributes array
--DESCRIPTION--
The declared `public array $attributes = []` must materialize to an empty array on
every PHP version. On PHP 7 the create_object callbacks (dd_init_span_data_object,
ddtrace_span_stack_create) must init property_attributes explicitly, since typed
array properties there default to NULL rather than their stub default.
--FILE--
<?php
class UserSpan extends \DDTrace\SpanData {
    public function __construct() {}
}

var_dump((new \DDTrace\SpanData())->attributes);
var_dump((new \DDTrace\RootSpanData())->attributes);
var_dump((new \DDTrace\InferredSpanData())->attributes);
var_dump((new \DDTrace\SpanStack())->attributes);
var_dump((new UserSpan())->attributes);

$span = new UserSpan();
$span->attributes['k'] = 'v';
var_dump($span->attributes);

// Tracer-created spans and stacks go through the same create_object callbacks.
$s = \DDTrace\start_span();
var_dump($s->attributes, $s->stack->attributes);
\DDTrace\close_span();
?>
--EXPECT--
array(0) {
}
array(0) {
}
array(0) {
}
array(0) {
}
array(0) {
}
array(1) {
  ["k"]=>
  string(1) "v"
}
array(0) {
}
array(0) {
}
