<?php

namespace DDTrace;

final class Obfuscation
{
    const REPLACEMENT = '?';
    const DEFAULT_GLUE = ' ';

    /**
     * Obfuscate secrets with a replacement
     *
     * @param string|array $keys
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

    /**
     * Obfuscate secrets from a DSN string
     *
     * @param string $dsn
     * @return string
     */
    public static function dsn($dsn)
    {
        if (false === strpos($dsn, '@')) {
            return $dsn;
        }
        return preg_replace(
            '/\/\/.+@/',
            '//' . self::REPLACEMENT . ':' . self::REPLACEMENT . '@',
            $dsn
        );
    }
}
