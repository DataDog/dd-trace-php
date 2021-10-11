<?php

// DDTrace\hook_method('MongoDB\Driver\Query', '__construct', null, function ($self, $_1, $args) {
//     error_log('>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>');
//     error_log('$1: ' . var_export($_1, true));
//     error_log('Args: ' . var_export($args, true));
// });
new \MongoDB\Driver\Manager(
    'mongodb://mongodb_integration',
    [
        'username' => 'test',
        'password' => 'test',
    ]
);

$query = new MongoDB\Driver\Query(['brand' => 'ferrari']);
