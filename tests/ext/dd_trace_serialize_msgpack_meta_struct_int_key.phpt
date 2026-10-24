--TEST--
dd_trace_serialize_msgpack() counts only the meta_struct entries it writes
--DESCRIPTION--
Integer keys in meta_struct are skipped, so the map must not count them.
--FILE--
<?php
$traces = [[[
    "meta_struct" => ["a" => "x", 0 => "y"],
]]];

echo implode(' ', str_split(bin2hex(dd_trace_serialize_msgpack($traces)), 2)), "\n";
?>
--EXPECT--
91 91 81 ab 6d 65 74 61 5f 73 74 72 75 63 74 81 a1 61 c4 01 78
