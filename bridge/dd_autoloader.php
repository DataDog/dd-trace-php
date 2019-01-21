<?php

namespace DDTrace\Bridge;

/**
 * Datadog psr4 autoloader.
 */
class Autoloader
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

        // Composing for the file path
        $absFilePath = $sourceDirectory . str_replace('\\', '/', $class) . '.php';

        if (file_exists($absFilePath)) {
            require_once $absFilePath;
        }
    }
}
