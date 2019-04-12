<?php

namespace DDTrace\Bridge;

/**
 * Datadog required depencencies psr4 autoloader.
 */
class RequiredDepsAutoloader
{
    private static $alreadyRequired = false;

    /**
     * @param string $class
     */
    public static function load($class)
    {
        if (self::$alreadyRequired) {
            return;
        }

        // project-specific namespace prefix
        $dataDogNamespaceRoot = 'DDTrace\\';

        // If it is not Datadog, let's exit soon
        $len = strlen($dataDogNamespaceRoot);
        if (strncmp($dataDogNamespaceRoot, $class, $len) !== 0) {
            return;
        }

        // load every required depency in one go
        require __DIR__ . '/dd_require_all.php';
        self::$alreadyRequired = true;
    }
}
