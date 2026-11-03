--TEST--
Line hook ends do not slide past comments or blank lines
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target() {
    echo "inside\n";
    // Requested end line with no code.

    echo "outside\n";
}

foreach ([
    'executable end' => [4, 4],
    'comment end' => [4, 5],
    'blank end' => [4, 6],
    'inclusive end' => [4, 7],
    'sliding begin' => [3, 3],
] as $label => $range) {
    list($begin, $end) = $range;
    echo "$label:\n";
    $id = DDTrace\install_line_hook(__FILE__, $begin, function () { echo "begin\n"; },
                                    $end, function () { echo "end\n"; });
    target();
    DDTrace\remove_hook($id);
}
?>
--EXPECT--
executable end:
begin
inside
end
outside
comment end:
begin
inside
end
outside
blank end:
begin
inside
end
outside
inclusive end:
begin
inside
outside
end
sliding begin:
begin
inside
end
outside
