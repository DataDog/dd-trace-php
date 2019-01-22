<?php

namespace DDTrace\Util;

class Environment
{
    /**
     * @param string $version
     * @return bool
     */
    public static function matchesPhpVersion($version)
    {
        return substr(phpversion(), 0, strlen($version)) === $version;
    }
}
