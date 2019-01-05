<?php

class SomeSymfonyInternalLoader
{
    private static function private_register()
    {
        error_log("Called SomeSymfonyInternalLoader::load()");
    }

    public function public_register()
    {
        spl_autoload_register('\SomeSymfonyInternalLoader::private_register');
    }
}

//if (class_exists('\Autoloader')) {
//    dd_trace('\Autoloader', 'register', function() {
//        return $this->register(func_get_args());
//    });
//}

dd_trace('spl_autoload_register', function() {
    $args = func_get_args();
    $originalAutoloaderRegistered = call_user_func_array('spl_autoload_register', $args);
    return $originalAutoloaderRegistered;
});

$a = new SomeSymfonyInternalLoader();
$a->public_register();

$b = new \some\other();
