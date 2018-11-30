<?php

namespace DDTrace\Configuration;

/**
 * Registry that holds configuration properties and that is able to recover configuration values from environment
 * variables.
 */
class EnvVariableRegistry
{
    /**
     * @var array
     */
    private $registry;

    public function __construct()
    {
        $this->registry = [];
    }

    /**
     * Extract a boolean configuration value, fallback to the corresponding env variable to read the value.
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function boolValue($key, $default)
    {
        if (!isset($this->registry[$key])) {
            $value = getenv($this->convertKeyToEnvVariableName($key));
            $value = trim(strtolower($value));
            if ($value === '1' || $value === 'true') {
                $this->registry[$key] = true;
            } elseif ($value === '0' || $value === 'false') {
                $this->registry[$key] = false;
            } else {
                $this->registry[$key] = $default;
            }
        }

        return $this->registry[$key];
    }

    /**
     * Given a dot separated key, it converts it to an expected variable name.
     *
     * e.g.: 'distributed_tracing' -> 'DD_DISTRIBUTED_TRACING'
     *
     * @param string $key
     * @return string
     */
    private function convertKeyToEnvVariableName($key)
    {
        return 'DD_' . trim(strtoupper(str_replace('.', '_', $key)));
    }
}
