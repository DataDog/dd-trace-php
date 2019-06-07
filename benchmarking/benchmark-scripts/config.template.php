<?php

/**
 * This is a template "config.php" file for the benchmark scripts.
 * Create a new directory in the "benchmark-scripts" directory and
 * copy this template to "config.php" in the new directory.
 *
 * $ cd benchmark-scripts && mkdir foo-bar
 * $ cp config.template.php foo-bar/config.php
 *
 * All the values are optional including the file itself.
 */

return [
    'name' => '', // Defaults to directory name when empty
    'ini' => [
        //'ddtrace.strict_mode' => '1',
    ],
    'env' => [
        //'DD_TRACE_DEBUG' => 'true',
    ],
    // TODO: 'min_tracer_version'
];
