<?php

namespace {
    if (function_exists('DDTrace\\trace_function')) {
        DDTrace\trace_function(
            'sleep',
            function ($span, $args) {
                $span->name = 'sleep';
                if (!empty($args[0])) {
                    $span->resource = (string)$args[0];
                }
                $span->type = 'custom';
            }
        );
    }

    function main()
    {
        sleep(1);
        echo "Done.\n";
    }

    main();
}
