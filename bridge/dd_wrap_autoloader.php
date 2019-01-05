<?php

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    return;
}

$dd_autoload_called = false;

dd_trace('spl_autoload_register', function() use (&$dd_autoload_called) {
    $args = func_get_args();

    $originalAutoloaderRegistered = call_user_func_array('spl_autoload_register', $args);

    if (!$dd_autoload_called) {
        $dd_autoload_called = true;
        require_once __DIR__ . '/dd_autoloader.php';
        spl_autoload_register(['\DDTrace\Bridge\Autoloader', 'load']);
    }

    require __DIR__ . '/dd_init.php';

    return $originalAutoloaderRegistered;
});
