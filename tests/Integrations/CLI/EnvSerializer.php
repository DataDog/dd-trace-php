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
            $envs[] = $name . '=' . escapeshellarg($value);
        }
        return implode(' ', $envs);
    }
}
