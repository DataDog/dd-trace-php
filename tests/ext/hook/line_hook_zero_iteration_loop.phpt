--TEST--
A range over a loop header closes at the loop's exit even when the body never runs
--DESCRIPTION--
The companion to line_hook_loop_header_range.phpt, which covers the loop running at least once.
Outgoing edges used to be enumerated over the address window alone, and pass_two puts the condition *after* the body -- so for a header-to-first-statement range the condition sat outside the scan and its false branch was never armed.
With a zero-iteration loop the range then stayed open across the work after it.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function for_range($limit) {
    for ($i = 0; $i < $limit; ++$i) {       // 4  begin
        echo "  inside $i\n";               // 5  end
    }
    echo "  after\n";
}

function while_range($limit) {
    $i = 0;
    while ($i < $limit) {                   // 12 begin
        echo "  inside $i\n";               // 13 end
        ++$i;
    }
    echo "  after\n";
}

$b = function () { echo "  BEGIN\n"; };
$e = function () { echo "  END\n"; };

echo "for, zero iterations:\n";
$f = DDTrace\install_line_hook(__FILE__, 4, $b, 5, $e);
var_dump($f < 0);
for_range(0);
DDTrace\remove_hook($f);

echo "while, zero iterations:\n";
$w = DDTrace\install_line_hook(__FILE__, 12, $b, 13, $e);
var_dump($w < 0);
while_range(0);
DDTrace\remove_hook($w);

echo "Done.\n";
?>
--EXPECT--
for, zero iterations:
bool(true)
  BEGIN
  END
  after
while, zero iterations:
bool(true)
  BEGIN
  END
  after
Done.
