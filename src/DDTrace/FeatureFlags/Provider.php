<?php

namespace DDTrace\FeatureFlags;

/**
 * Datadog Feature Flags and Experimentation (FFE) Provider.
 *
 * Evaluates feature flags using UFC v1 configurations received via Remote Config.
 * Reports exposure events to the EVP proxy for analytics.
 */
class Provider
{
    /** @var Evaluator */
    private $evaluator;

    /** @var ExposureWriter */
    private $writer;

    /** @var LRUCache */
    private $exposureCache;

    /** @var bool */
    private $enabled;

    /** @var bool */
    private $configReceived = false;

    /** @var Provider|null */
    private static $instance = null;

    public function __construct()
    {
        $this->evaluator = new Evaluator();
        $this->writer = new ExposureWriter();
        $this->exposureCache = new LRUCache(65536);
        $this->enabled = $this->isFeatureFlagEnabled();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset the singleton (useful for testing).
     */
    public static function reset()
    {
        self::$instance = null;
    }

    /**
     * Initialize the provider: check for new config from Remote Config.
     */
    public function start()
    {
        if (!$this->enabled) {
            return false;
        }
        $this->pollForConfig();
        return true;
    }

    /**
     * Poll for new FFE configuration from the Rust/C layer.
     */
    public function pollForConfig()
    {
        $configJson = \dd_trace_internal_fn('get_ffe_config');
        if ($configJson !== null && $configJson !== false) {
            $config = json_decode($configJson, true);
            if (is_array($config)) {
                $this->evaluator->setConfig($config);
                $this->configReceived = true;
            }
        }
    }

    /**
     * Evaluate a feature flag.
     *
     * @param string $flagKey The flag key to evaluate
     * @param string $variationType The expected variation type (STRING, BOOLEAN, INTEGER, NUMERIC, JSON)
     * @param mixed $defaultValue The default value to return if evaluation fails
     * @param string|null $targetingKey The targeting key (user/subject ID)
     * @param array $attributes Additional context attributes
     * @return array ['value' => mixed, 'reason' => string]
     */
    public function evaluate($flagKey, $variationType, $defaultValue, $targetingKey, $attributes = [])
    {
        if (!$this->enabled) {
            return ['value' => $defaultValue, 'reason' => 'DISABLED'];
        }

        // Check for config updates
        $this->pollForConfig();

        if (!$this->configReceived) {
            return ['value' => $defaultValue, 'reason' => 'DEFAULT'];
        }

        $context = [
            'targeting_key' => $targetingKey !== null ? $targetingKey : '',
            'attributes' => is_array($attributes) ? $attributes : [],
        ];

        $result = $this->evaluator->resolveFlag($flagKey, $variationType, $context);

        if ($result === null) {
            return ['value' => $defaultValue, 'reason' => 'DEFAULT'];
        }

        if (isset($result['error_code'])) {
            return ['value' => $defaultValue, 'reason' => 'ERROR'];
        }

        // Report exposure event
        if (!empty($result['do_log']) && $result['variant'] !== null && $result['allocation_key'] !== null) {
            $this->reportExposure(
                $flagKey,
                $result['variant'],
                $result['allocation_key'],
                $targetingKey,
                $attributes
            );
        }

        // Return the evaluated value, or default if no variant matched
        if ($result['variant'] === null || $result['value'] === null) {
            return ['value' => $defaultValue, 'reason' => isset($result['reason']) ? $result['reason'] : 'DEFAULT'];
        }

        return [
            'value' => $result['value'],
            'reason' => isset($result['reason']) ? $result['reason'] : 'TARGETING_MATCH',
        ];
    }

    /**
     * Report a feature flag exposure event.
     */
    private function reportExposure($flagKey, $variantKey, $allocationKey, $targetingKey, $attributes)
    {
        if (!$variantKey || !$allocationKey) {
            return;
        }

        $subjectId = $targetingKey !== null ? $targetingKey : '';
        $cacheKey = $flagKey . '|' . $subjectId;
        $cacheValue = $allocationKey . '|' . $variantKey;

        $cached = $this->exposureCache->get($cacheKey);
        if ($cached !== null && $cached === $cacheValue) {
            return;
        }

        $event = ExposureWriter::buildEvent(
            $flagKey,
            $variantKey,
            $allocationKey,
            $subjectId,
            is_array($attributes) ? $attributes : []
        );

        $this->writer->enqueue($event);
        $this->exposureCache->set($cacheKey, $cacheValue);
    }

    /**
     * Flush pending exposure events.
     */
    public function flush()
    {
        $this->writer->flush();
    }

    /**
     * Clear the exposure cache.
     */
    public function clearExposureCache()
    {
        $this->exposureCache->clear();
    }

    /**
     * Check if the feature flag provider is enabled via env var.
     */
    private function isFeatureFlagEnabled()
    {
        // Check env var first (most reliable across all SAPIs)
        $envVal = getenv('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED');
        if ($envVal !== false) {
            return strtolower($envVal) === 'true' || $envVal === '1';
        }

        // Fall back to INI config
        if (function_exists('dd_trace_env_config')) {
            return (bool)\dd_trace_env_config('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED');
        }

        return false;
    }

    /**
     * Check if config has been received.
     */
    public function isReady()
    {
        return $this->configReceived;
    }
}
