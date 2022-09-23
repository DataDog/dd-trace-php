--TEST--
Test dropping spans from multiple trace hooks
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function a() {}

DDTrace\trace_function("a", function() { }); // first function, creates span
DDTrace\trace_function("a", function() { return false; });

a();
var_dump(count(dd_trace_serialize_closed_spans()));

dd_untrace("a");
DDTrace\trace_function("a", function() { }); // first function, creates span
DDTrace\trace_function("a", ["prehook" => function() { return false; }]);

a();
var_dump(count(dd_trace_serialize_closed_spans()));

dd_untrace("a");
DDTrace\trace_function("a", function() { return false; }); // first function, creates span
DDTrace\trace_function("a", function() { });

a();
var_dump(count(dd_trace_serialize_closed_spans()));

// Note that in this case we have a prehook as first function. A prehook returning false will drop an existing span, but not affect future spans.
dd_untrace("a");
DDTrace\trace_function("a", ["prehook" => function() { return false; }]); // first function, creates span
DDTrace\trace_function("a", function() { });

a();
var_dump(count(dd_trace_serialize_closed_spans()));

?>
--EXPECT--
int(0)
int(0)
int(0)
int(1)
