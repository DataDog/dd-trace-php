<?php

namespace RandomizedTests;

class Utils
{
    public static function isPhpVersion($major, $minor)
    {
        return PHP_MAJOR_VERSION === $major && PHP_MINOR_VERSION === $minor;
    }
}
