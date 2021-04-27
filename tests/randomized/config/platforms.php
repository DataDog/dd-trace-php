<?php

// List of available OSes and the php versions they support. Values from this array have a direct correlation to the
// name of the image used to run tests. For example:
//   - OS[centos7][php][7.4] --> datadog/dd-trace-ci:php-randomizedtests-centos7-7.4
const OS = [
    'centos7' => [
        'php' => [
            '8.0',
            '7.4',
            '7.3',
            '7.2',
            '7.1',
            '7.0',
            '5.6',
            '5.5',
            '5.4',
        ],
    ],
];

// Installation methods. Currently only package is supported (the pre-built .tar.gz file). In the future we want to
// support pecl installation as well.
const INSTALLATION = [
    'package',
];
