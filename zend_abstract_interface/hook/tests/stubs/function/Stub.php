<?php
namespace DDTraceTesting {

    function target() {
        return 42;
    }
}

namespace {

    \ddtrace_testing_hook_function('\DDTraceTesting\target', 
        function() {
            echo "I AM HERE IN " . __FUNCTION__ . "\n";
            \ddtrace_testing_hook_begin_check();
            var_dump(\ddtrace_testing_hook_begin_return());
            return \ddtrace_testing_hook_begin_return();
        },
        function($rv = null){
            echo "I AM HERE IN " . __FUNCTION__ . " TOO\n";
            \ddtrace_testing_hook_end_check();
        }
    );
}
?>
