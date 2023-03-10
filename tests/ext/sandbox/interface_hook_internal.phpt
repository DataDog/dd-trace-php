--TEST--
[Sandbox] Hook implementations of internal interface methods
--FILE--
<?php

DDTrace\hook_method("Throwable", "__toString", function() {
    echo "Throwable HOOK\n";
});

class CustomError extends Error {
    #[ReturnTypeWillChange]
    public function __toString() {
        return "CustomError __toString\n";
    }
}

echo (new Exception("Exception __toString"))->__toString(), "\n";
echo (new CustomError)->__toString();

dd_untrace("__toString", "Throwable");

echo (new Exception("Exception __toString\n"))->__toString(), "\n";
echo (new CustomError)->__toString();

?>
--EXPECTF--
Throwable HOOK
Exception: Exception __toString in %s
Stack trace:
#0 {main}
Throwable HOOK
CustomError __toString
Exception: Exception __toString
 in %s
Stack trace:
#0 {main}
CustomError __toString
