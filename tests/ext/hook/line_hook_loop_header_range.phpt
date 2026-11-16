--TEST--
A range from a loop header through its first statement covers that statement
--DESCRIPTION--
Range membership used to be an opcode-address interval, but pass_two lays a loop's increment and condition *after* its body.
A header-to-first-statement range therefore had its own condition sitting past the end address, so the header's initial jump to that condition looked like an exit and closed the range before the body ran.
Membership is a source-line question; compiler-inserted hops are followed to where they land, because such a jump carries the line of the construct it belongs to rather than of the code it leads to.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function for_range($limit) {
    for ($i = 0; $i < $limit; ++$i) {       // 4  begin
        echo "  inside $i\n";               // 5  end
        echo "  outside $i\n";
    }
    echo "  after\n";
}

function while_range($limit) {
    $i = 0;
    while ($i++ < $limit) {                 // 13 begin
        echo "  inside $i\n";               // 14 end
        echo "  outside $i\n";
    }
    echo "  after\n";
}

$b = function () { echo "  BEGIN\n"; };
$e = function () { echo "  END\n"; };

echo "for:\n";
$f = DDTrace\install_line_hook(__FILE__, 4, $b, 5, $e);
var_dump($f < 0);
for_range(2);
DDTrace\remove_hook($f);

echo "while:\n";
$w = DDTrace\install_line_hook(__FILE__, 13, $b, 14, $e);
var_dump($w < 0);
while_range(2);
DDTrace\remove_hook($w);

echo "Done.\n";
?>
--EXPECT--
for:
bool(true)
  BEGIN
  inside 0
  END
  outside 0
  inside 1
  outside 1
  after
while:
bool(true)
  BEGIN
  inside 1
  END
  outside 1
  inside 2
  outside 2
  after
Done.
