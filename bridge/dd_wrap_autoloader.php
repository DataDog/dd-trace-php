<?php

if (php_sapi_name() == 'cli' && getenv('APP_ENV') != 'dd_testing') {
    return;
}

dd_trace('Composer\Autoload\ClassLoader', 'register', function() {});



//$dd_autoload_called = false;
//
//dd_trace('spl_autoload_register', function() use (&$dd_autoload_called) {
//    $args = func_get_args();
//    $callback = $args[0];
//    error_log("######################################################################");
//
//    if (is_array($callback)) {
//        if (is_string($callback[0])) {
//            error_log("Callback: " . $callback[0] . '::' . $callback[1]);
//        } else {
//            error_log("Callback: " . get_class($callback[0]) . '::' . $callback[1]);
//        }
//    } elseif (is_object($callback)) {
//        error_log("Callback: " . get_class($callback));
//    } else {
//        error_log("Callback: " . $callback);
//    }
//
//    if ($callback === 'Symfony\Component\Config\Resource\ClassExistenceResource::throwOnRequiredClass') {
//        throw new Exception("Ciaone");
//    }
//
//    $originalAutoloaderRegistered = call_user_func_array('spl_autoload_register', $args);
//
//    error_log("Call was good...");
//
//    require __DIR__ . '/dd_init.php';
//
//    error_log("######################################################################");
//    return $originalAutoloaderRegistered;
//});

//class Autoloader {
//    public static function load(){
//        error_log("Called load");
//    }
//}





//require_once __DIR__ . '/dd_autoloader.php';
//// The autoloader is not prepended, so it is registered after the composer autoloaded and the symfony autoloader,
//// we applicable.
//spl_autoload_register('DDTrace\Bridge\Autoloader::hook');
//spl_autoload_register('DDTrace\Bridge\Autoloader::load');
//
//// We use our own autoloader call as an hook to fire the init invocation. This will not work for cases when the user
//// declare the ddtrace dependency in composer, so in that case we have to find a different way.
//dd_trace('DDTrace\Bridge\Autoloader', 'load', function() {
//    $args = func_get_args();
//    require __DIR__ . '/dd_init.php';
//    return call_user_func_array(['DDTrace\Bridge\Autoloader', 'load'], $args);
//});