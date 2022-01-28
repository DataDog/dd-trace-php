<?php
namespace DDTraceTesting {

    function target() {
        return 42;
    }
}

namespace {
    \ddtrace_testing_hook_function('\DDTraceTesting\target', 
        function($aux) {
            \ddtrace_testing_hook_begin_check();

            return \ddtrace_testing_hook_begin_return();
        },
        function($aux, $rv = null){
            \ddtrace_testing_hook_end_check();
        }
    );
}
?>
