<?php

namespace DDTrace\Configuration;

/**
 * Registry that holds configuration properties and that is able to recover configuration values from environment
 * variables.
 */
class EnvVariableRegistry implements Registry
{
    /**
     * @var array
     */
    private $registry;

    /**
     * @var string
     */
    private $prefix;

    /**
     * @param string $prefix
     */
    public function __construct($prefix = 'DD_')
    {
        $this->prefix = $prefix;
        $this->registry = [];
    }

    /**
     * Return an env variable that starts with "DD_".
     *
     * @param string $key
     * @return string|null
     */
    protected function get($key)
    {
        $value = getenv($this->convertKeyToEnvVariableName($key));
        if (false === $value) {
            return null;
        }
        return trim($value);
    }

    /**
     * {@inheritdoc}
     */
    public function stringValue($key, $default)
    {
        if (isset($this->registry[$key])) {
            return $this->registry[$key];
        }
        $value = $this->get($key);
        if (null !== $value) {
            return $this->registry[$key] = $value;
        } else {
            return $this->registry[$key] = $default;
        }
        return $this->registry[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function boolValue($key, $default)
    {
        if (isset($this->registry[$key])) {
            return $this->registry[$key];
        }

        $value = $this->get($key);
        if (null === $value) {
            return $default;
        }

        $value = strtolower($value);
        if ($value === '1' || $value === 'true') {
            $this->registry[$key] = true;
        } elseif ($value === '0' || $value === 'false') {
            $this->registry[$key] = false;
        } else {
            $this->registry[$key] = $default;
        }

        return $this->registry[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function floatValue($key, $default, $min = null, $max = null)
    {
        if (!isset($this->registry[$key])) {
            $value = $this->get($key);
            $value = strtolower($value);
            if (is_numeric($value)) {
                $floatValue = (float)$value;
            } else {
                $floatValue = (float)$default;
            }

            if (null !== $min && $floatValue < $min) {
                $floatValue = $min;
            }

            if (null !== $max && $floatValue > $max) {
                $floatValue = $max;
            }

            $this->registry[$key] = $floatValue;
        }

        return $this->registry[$key];
    }

    /**
     * Given a string like 'key1:value1,key2:value2', it returns an associative array
     * ['key1'=> 'value1', 'key2'=> 'value2']
     *
     * @param string $key
     * @return string[]
     */
    public function associativeStringArrayValue($key)
    {
        if (isset($this->registry[$key])) {
            return $this->registry[$key];
        }

        $default = [];
        $value = $this->get($key);

        if (null === $value) {
            return $default;
        }

        // For now we provide no escaping
        $this->registry[$key] = [];
        $elements = explode(',', $value);
        foreach ($elements as $element) {
            $keyAndValue = explode(':', $element);

            if (count($keyAndValue) !== 2) {
                continue;
            }

            $keyFragment = trim($keyAndValue[0]);
            $valueFragment = trim($keyAndValue[1]);

            if (empty($keyFragment)) {
                continue;
            }

            $this->registry[$key][$keyFragment] = $valueFragment;
        }

        return $this->registry[$key];
    }

    /**
     * {@inheritdoc}
     */
    public function inArray($key, $name)
    {
        if (!isset($this->registry[$key])) {
            $value = $this->get($key);
            if (null !== $value) {
                $disabledIntegrations = explode(',', $value);
                $this->registry[$key] = array_map(function ($entry) {
                    return strtolower(trim($entry));
                }, $disabledIntegrations);
            } else {
                $this->registry[$key] = [];
            }
        }

        return in_array(strtolower($name), $this->registry[$key], true);
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
        return $this->prefix . strtoupper(str_replace('.', '_', trim($key)));
    }
}
