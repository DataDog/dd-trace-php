--TEST--
Ranges close at the instruction that leaves them, on every control-flow representation
--DESCRIPTION--
Begin/end eventually pairing is not the contract: the end has to land *before* the work outside the range.
Three edges used to miss that.
ZEND_FAST_RET's target is computed at run time, so a range inside a finally stayed open until frame exit -- but the set of possible targets is static, being the instruction after each FAST_CALL that reaches the finally.
The switch jump tables and the catch no-match edge were skipped below 7.3 only because their operands are spelled differently there, not because they are unhookable.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function with_switch($n) {
    switch ($n) {
        case 0:
            echo "  case0\n";              // 6
            break;
        case 4:
            echo "  case4\n";
            break;
    }
    echo "  after switch\n";
}

function with_catch() {
    try {
        throw new RuntimeException('x');
    } catch (TypeError $e) {               // 18  no match: falls through to the next catch
        echo "  wrong\n";
    } catch (RuntimeException $e) {
        echo "  right\n";
    }
    echo "  after catch\n";
}

function with_finally_continue() {
    for ($i = 0; $i < 2; ++$i) {
        try {
            continue;
        } finally {
            echo "  work $i\n";            // 31
        }
    }
    echo "  after loop\n";
}

$b = function () { echo "  BEGIN\n"; };
$e = function () { echo "  END\n"; };

// A range from the switch header into the first case: selecting a later case must close it at the jump target, not after the switch has finished.
echo "switch:\n";
$s = DDTrace\install_line_hook(__FILE__, 4, $b, 6, $e);
with_switch(4);
DDTrace\remove_hook($s);

// A range over the first catch header only: the exception matching a later catch must close it.
echo "catch:\n";
$c = DDTrace\install_line_hook(__FILE__, 18, $b, 18, $e);
with_catch();
DDTrace\remove_hook($c);

// A range inside a finally reached by `continue`: closes each iteration, not after the loop.
echo "finally:\n";
$f = DDTrace\install_line_hook(__FILE__, 31, $b, 31, $e);
with_finally_continue();
DDTrace\remove_hook($f);

echo "Done.\n";
?>
--EXPECT--
switch:
  BEGIN
  END
  case4
  after switch
catch:
  BEGIN
  END
  right
  after catch
finally:
  BEGIN
  work 0
  END
  BEGIN
  work 1
  END
  after loop
Done.
