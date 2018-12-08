<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace\Configuration;
use Illuminate\Config\Repository;

/**
 * Registry that holds configuration properties and that is able to recover
 * configuration values from environment variables.
 */
class ConfigRegistry extends Configuration
{
    /** @var \Illuminate\Config\Repository */
    private $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * {@inheritdoc}
     */
    public function boolValue($key, $default)
    {
        return (bool) $this->getValueFromRepository($key, $default);
    }

    /**
     * {@inheritdoc}
     */
    public function inArray($key, $name)
    {
        $value = $this->getValueFromRepository($key);

        if (is_string($value)) {
            $value = explode(',', $value);
            $value = array_map(function ($entry) {
                return trim(strtolower($entry));
            }, $value);
            $this->setValueInRepository($key, $value);
        } elseif (!is_array($value)) {
            $value = [];
        }

        return in_array(strtolower($name), $value);
    }

    /**
     * Get value from the repository.
     *
     * @param string $key
     * @param mixed $default
     * @return string
     */
    private function getValueFromRepository($key, $default = null)
    {
        return $this->repository->get($this->convertKeyToRepositoryKey($key), $default);
    }

    /**
     * Set value in the repository.
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private function setValueInRepository($key, $value)
    {
        return $this->repository->set($this->convertKeyToRepositoryKey($key), $value);
    }

    /**
     * Converts dot-separated tracer configuration key to the corresponding
     * config repository key.
     *
     * @param string $key
     * @return string
     */
    private function convertKeyToRepositoryKey($key)
    {
        return 'ddtrace.' . trim(str_replace('.', '_', $key));
    }
}
