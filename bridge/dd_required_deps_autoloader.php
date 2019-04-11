<?php

namespace DDTrace\Bridge;

/**
 * Datadog required depencencies psr4 autoloader.
 */
class RequiredDepsAutoloader
{
    /**
     * @param string $class
     */
    public static function load($class)
    {
        // project-specific namespace prefix
        $dataDogNamespaceRoot = 'DDTrace\\';

        // If it is not Datadog, let's exit soon
        $len = strlen($dataDogNamespaceRoot);
        if (strncmp($dataDogNamespaceRoot, $class, $len) !== 0) {
            return;
        }

        // load every required depency in one go and unregister
        require_once __DIR__ . '/dd_require_all.php';
        spl_autoload_unregister(['\DDTrace\Bridge\RequireOnceAutoloader', 'load']);
    }
}
