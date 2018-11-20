<?php

namespace DDTrace;

class Obfuscation
{
    const REPLACEMENT = '?';
    const DEFAULT_GLUE = ' ';

    /**
     * Obfuscate secrets with a replacement
     *
     * @param mixed $keys
     * @param string $glue
     * @return string
     */
    public static function toObfuscatedString($keys, $glue = self::DEFAULT_GLUE)
    {
        if (!is_array($keys)) {
            return self::REPLACEMENT;
        }
        $obfuscatedKeys = str_repeat(self::REPLACEMENT . $glue, count($keys));
        return rtrim($obfuscatedKeys, $glue);
    }
}
