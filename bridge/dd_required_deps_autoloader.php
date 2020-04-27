<?php

namespace DDTrace\Bridge;

/**
 * Datadog required depencencies psr4 autoloader.
 */
class RequiredDepsAutoloader
{
    private static $skipLoader = false;

    /**
     * @param string $class
     */
    public static function load($class)
    {
        if (self::$skipLoader) {
            return;
        }

        // project-specific namespace prefix
        $dataDogNamespaceRoot = 'DDTrace\\';

        // If it is not Datadog, let's exit soon
        $len = strlen($dataDogNamespaceRoot);
        if (strncmp($dataDogNamespaceRoot, $class, $len) !== 0) {
            return;
        }

        self::$skipLoader = true; // avoid hard to debug errors if dd_require_all requires dependencies in wrong order
        // load every required depency in one go
        require __DIR__ . '/autoload.php';
    }
}
