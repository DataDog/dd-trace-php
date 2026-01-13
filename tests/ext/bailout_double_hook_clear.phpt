--TEST--
Bailout during hook END should not cause double-clear of span (internal function)
--FILE--
<?php

DDTrace\trace_function('array_sum', [
    'posthook' => function(\DDTrace\SpanData $data, $args, $retval) {
        echo "Hook 0 END\n";
    }
]);

DDTrace\trace_function('array_sum', [
    'prehook' => function (\DDTrace\SpanData $data, $args) {
        echo "HOOK 1 PRE\n";
    },
    'posthook' => function(\DDTrace\SpanData $span, $args, $retval) {
        static $ba = true;
        echo "Hook 1 END\n";
        if ($ba) {
            echo "WILL BAILOUT!\n";
        }
        trigger_error("Datadog blocked the request", E_USER_ERROR);
    }
]);

DDTrace\trace_function('array_sum', [
    'posthook' => function(\DDTrace\SpanData $data, $args, $retval) {
        echo "Hook 2 END\n";
    }
]);

register_shutdown_function(function() {
    echo "Shutdown function executed\n";
});

echo "Calling array_sum\n";
array_sum([1, 2, 3]);
echo "After array_sum (should not print)\n";

?>
--EXPECTF--
Calling array_sum
HOOK 1 PRE
Hook 2 END
Hook 1 END
WILL BAILOUT!
Shutdown function executed
