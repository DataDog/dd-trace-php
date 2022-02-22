<?php

namespace DDTrace\Util;

/**
 * Utilities related to the PHP runtime
 */
final class Runtime
{
    /**
     * Tells whether or not a given autoloader is registered.
     *
     * @param string $class
     * @param string $method
     * @return bool
     */
    public static function isAutoloaderRegistered($class, $method)
    {
        $class = trim($class, '\\');
        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $autoloader) {
            if (!is_array($autoloader) || count($autoloader) !== 2) {
                continue;
            }

            $registeredAutoloader = $autoloader[0];
            $registeredMethod = $autoloader[1];
            if (is_string($registeredAutoloader)) {
                $compareClass = trim($registeredAutoloader, '\\');
            } elseif (is_object($registeredAutoloader)) {
                $compareClass = trim(get_class($registeredAutoloader), '\\');
            } else {
                continue;
            }

            if ($compareClass === $class && $registeredMethod === $method) {
                return true;
            }
        }

        return false;
    }

    public static function getBoolIni($iniName)
    {
        $ini = ini_get($iniName);
        return $ini === "on" || $ini === "yes" || $ini === "true" || (int) $ini;
    }
}
