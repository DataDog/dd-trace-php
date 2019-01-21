<?php

require_once __DIR__ . '/functions.php';

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    return;
}

if (!dd_tracing_enabled()) {
    return;
}

$dd_autoload_called = false;

// Instead of tracing autoloaders statically, we should trace them dynamically. This can be done at the moment because
// of https://github.com/DataDog/dd-trace-php/issues/224 and the fact that in some cases, e.g. Symfony's
// `Symfony\Component\Config\Resource\ClassExistenceResource::throwOnRequiredClass` loaders are private.
// As soon as this is fixed we can trace `spl_autoload_register` function and use it as a hook instead of
// statically hooking into a limited number of class loaders.
dd_trace('spl_autoload_register', function () use (&$dd_autoload_called) {
    $args = func_get_args();
    $originalAutoloaderRegistered = call_user_func_array('spl_autoload_register', $args);

    $loader = $args[0];

    // Why unregistering spl_autoload_register?
    // In some cases (e.g. Symfony) this 'spl_autoload_register' function is called within a private scope and at the
    // moment we are working to have this use case properly handled by the extension. In the meantime we provide
    // this workaround.
    if (is_array($loader) && $loader[0] === 'Composer\Autoload\ClassLoader' && $loader[1] === 'loadClass') {
        dd_untrace('spl_autoload_register');
    }

    if (!$dd_autoload_called) {
        $dd_autoload_called = true;
        require_once __DIR__ . '/dd_autoloader.php';
        spl_autoload_register(['\DDTrace\Bridge\Autoloader', 'load']);
    }

    require __DIR__ . '/dd_init.php';

    return $originalAutoloaderRegistered;
});
