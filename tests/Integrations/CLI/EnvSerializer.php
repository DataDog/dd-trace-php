<?php

namespace DDTrace\Tests\Integrations\CLI;

/**
 * Serialize an associative array into env var string for CLI commands
 */
final class EnvSerializer
{
    private $envs;

    /**
     * @param array $envs
     */
    public function __construct(array $envs)
    {
        $this->envs = $envs;
    }

    public function __toString()
    {
        $envs = [];
        foreach ($this->envs as $name => $value) {
            $envs[] = $name . '=' . escapeshellarg($this->normalizeValue($value));
        }
        return implode(' ', $envs);
    }

    private function normalizeValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }
}
