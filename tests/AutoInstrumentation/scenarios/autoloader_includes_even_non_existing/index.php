<?php

/**
 * Replicating Zend_Loader approach to class loading, where a file tried with 'include_once' even this is not a
 * known namespace for it.
 */
class AutoloaderThrowsException
{
    public static function load($class)
    {
        include_once 'I/Do/Not/Exist.php';
    }
}

spl_autoload_register('AutoloaderThrowsException::load');

class_exists('I\Do\Not\Exist');

echo DDTrace\Tracer::VERSION;
