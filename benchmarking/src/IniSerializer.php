<?php

namespace DDTrace\Benchmark;

/**
 * Serialize an associative array into INI string for CLI SAPI
 */
final class IniSerializer
{
    public static function serialize(array $rawInis)
    {
        $inis = [];
        foreach ($rawInis as $name => $value) {
            $inis[] = '-d' . $name . '=' . escapeshellarg($value);
        }
        return implode(' ', $inis);
    }
}
