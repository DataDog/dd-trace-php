<?php

namespace DDTrace\Bridge;

/**
 * Datadog psr4 autoloader.
 */
class RequireOnceAutoloader
{
    /**
     * @param string $class
     */
    public static function load($class)
    {
        // project-specific namespace prefix
        $dataDogNamespaceRoot = 'DDTrace\\';
        $sourceDirectory = __DIR__ . '/../src/';

        // If it is not Datadog, let's exit soon
        $len = strlen($dataDogNamespaceRoot);
        if (strncmp($dataDogNamespaceRoot, $class, $len) !== 0) {
            return;
        }
        require_once __DIR__ . '/dd_require_all.php';
        spl_autoload_unregister(['\DDTrace\Bridge\RequireOnceAutoloader', 'load']);
    }
}
