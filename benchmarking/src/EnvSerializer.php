<?php

namespace DDTrace\Benchmark;

/**
 * Serialize an associative array into env var string for CLI commands
 */
final class EnvSerializer
{
    public static function serialize(array $rawEnvs)
    {
        $envs = [];
        foreach ($rawEnvs as $name => $value) {
            $envs[] = $name . '=' . escapeshellarg($value);
        }
        return implode(' ', $envs);
    }
}
