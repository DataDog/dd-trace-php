--TEST--
Exceptions from original call rethrown in tracing closure
--FILE--
<?php

function a(){
    echo "a()\n";
    throw new Exception('Oops!');
}

DDTrace\trace_function('a', function($s, $args, $r, $ex) {
    $s->name = 'a';
    throw $ex;
});

try {
    a();
} catch (Exception $e) {
    //
}

array_map(function($span) {
    printf(
        "%s with exception: %s\n",
        $span['name'],
        $span['meta']['error.message']
    );
}, dd_trace_serialize_closed_spans());
?>
--EXPECTF--
a()
a with exception: Thrown Exception: Oops! in %s:%d
