<?php

// Values from this array might be selected and set. When an ini value from this list is selected,
// then there is an equal probability that any of the assigned values from this array can be set.
// NOTE: flags should be boolean values and are converted while generating the file to the proper value.
const INIS = [
    'opcache.enable' => [false],
    'opcache.jit_buffer_size' => ['256M'],
    'extension' => ['datadog-profiling.so'],
];
