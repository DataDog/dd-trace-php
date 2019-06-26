<?php

/**
 * Replicating Zend_Loader approach to class loading, where a file tried with 'include_once' even this is not a
 * known namespace for it.
 */
class AutoloaderTryAlwaysToLoadFile
{
    public static function load($class)
    {
        include_once 'I/Do/Not/Exist.php';
    }
}

spl_autoload_register('AutoloaderTryAlwaysToLoadFile::load');

// The error would be thrown just because during auto-instrumentation we test for existence of some classes and when
// such classes are not found and we get to the `AutoloaderTryAlwaysToLoadFile` it tries to include a non existing
// files, causing and error.

echo DDTrace\Tracer::version();
