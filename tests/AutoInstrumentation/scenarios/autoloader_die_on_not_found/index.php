<?php

/**
 * Replicating cases when the class loader dies if file not found.
 */
class AutoloaderDieOnNotFound
{
    public static function load($class)
    {
        die('I/Do/Not/Exist.php');
    }
}

spl_autoload_register('AutoloaderDieOnNotFound::load');

echo DDTrace\Tracer::version();
