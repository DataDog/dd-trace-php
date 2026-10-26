--TEST--
Add meta struct string; empty and numeric keys are kept (numeric ones as decimal strings)
--FILE--
<?php
function dd_trace_unserialize_trace_hex($meta_struct) {
    foreach($meta_struct as $key => $value) {
        $hex = [];
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $hex[] = bin2hex($value[$i]);
        }
        var_dump($key, implode(' ', $hex));
    }
}

$span = DDTrace\start_span();
$span->meta_struct["foo"] = "bar";
$span->meta_struct["john"] = ["Doe"];
$span->meta_struct[""] = "e";
$span->meta_struct[7] = "n";
DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans()['chunks'][0]['spans'];

foreach ($spans as $span)
{
    dd_trace_unserialize_trace_hex($span["meta_struct"]);
}

?>
--EXPECTF--
string(3) "foo"
string(11) "a3 62 61 72"
string(4) "john"
string(14) "91 a3 44 6f 65"
string(0) ""
string(5) "a1 65"
int(7)
string(5) "a1 6e"
