--TEST--
Ensure caching does not bypass the span data write handler
--FILE--
<?php

function assign_parent_id($id) {
    \DDTrace\root_span()->parentId = $id;
}

assign_parent_id("123");
var_dump(\DDTrace\root_span()->parentId);
assign_parent_id("abc");
var_dump(\DDTrace\root_span()->parentId);
assign_parent_id(-1);
var_dump(\DDTrace\root_span()->parentId);

?>
--EXPECT--
string(3) "123"
string(0) ""
string(20) "18446744073709551615"
