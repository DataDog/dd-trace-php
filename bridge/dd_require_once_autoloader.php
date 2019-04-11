<?php

namespace DDTrace\Bridge;

/**
 * Datadog psr4 autoloader.
 */
class RequireOnceAutoloader
{
    /**
     * @var array
     */
    private static $autoloaderMapping = [
        "DDTrace\\Integrations\\ZendFramework\V1\TraceRequest" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/TraceRequest.php',
        "DDTrace_Ddtrace" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/Ddtrace.php'
    ];

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

        require_once __DIR__ . '/dd_require_all.php';

        spl_autoload_unregister(['\DDTrace\Bridge\RequireOnceAutoloader', 'load']);
        spl_autoload_register(['\DDTrace\Bridge\RequireOnceAutoloader', 'loadOptionalDependencies']);
    }

    public static function loadOptionalDependencies($class)
    {
        if (array_key_exists($class, self::$autoloaderMapping)) {
            require_once self::$autoloaderMapping[$class];
        }
    }
}
