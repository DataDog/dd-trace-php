--TEST--
A range spanning nested finally blocks keeps both of them inside it
--DESCRIPTION--
Returning from an inner finally lands on the FAST_CALL that enters the *outer* one, and that instruction is tagged with the line of the return or continue that began the unwind -- not with where control is going.
Judging it on its own line closed the range before the outer finally ran, so compiler-inserted transfers are resolved to the instruction they actually lead to before membership is decided.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function nested_finally($value) {
    try {
        try {
            return $value;
        } finally {
            echo "  inner $value\n";        // 8  begin
        }
    } finally {
        echo "  outer $value\n";            // 11 end
    }
}

$id = DDTrace\install_line_hook(__FILE__, 8, function () { echo "  BEGIN\n"; },
                                         11, function () { echo "  END\n"; });
var_dump($id < 0);
var_dump(nested_finally('value'));
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECT--
bool(true)
  BEGIN
  inner value
  outer value
  END
string(5) "value"
Done.
