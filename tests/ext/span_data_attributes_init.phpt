--TEST--
Directly constructed span/stack objects expose an initialized attributes array
--DESCRIPTION--
The declared `public array $attributes = []` must materialize to an empty array on
every PHP version. On PHP 7 the create_object callbacks (dd_init_span_data_object,
ddtrace_span_stack_create) must init property_attributes explicitly, since typed
array properties there default to NULL rather than their stub default.
--FILE--
<?php
var_dump((new \DDTrace\SpanData())->attributes);
var_dump((new \DDTrace\RootSpanData())->attributes);
var_dump((new \DDTrace\SpanStack())->attributes);
?>
--EXPECT--
array(0) {
}
array(0) {
}
array(0) {
}
