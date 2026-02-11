<?php

namespace DDTrace\FeatureFlags;

/**
 * Datadog Feature Flags and Experimentation (FFE) Provider.
 *
 * Evaluates feature flags using the native datadog-ffe engine from libdatadog.
 * Flag configurations are received via Remote Config through the sidecar.
 * Reports exposure events to the EVP proxy for analytics.
 */
class Provider
{
    private static $REASON_MAP = [
        0 => 'STATIC',
        1 => 'DEFAULT',
        2 => 'TARGETING_MATCH',
        3 => 'SPLIT',
        4 => 'DISABLED',
        5 => 'ERROR',
    ];

    private static $TYPE_MAP = [
        'STRING'  => 0,
        'INTEGER' => 1,
        'NUMERIC' => 2,
        'BOOLEAN' => 3,
        'JSON'    => 4,
    ];

    /** @var ExposureWriter */
    private $writer;

    /** @var ExposureCache */
    private $exposureCache;

    /** @var bool */
    private $enabled;

    /** @var bool */
    private $configLoaded = false;

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var Provider|null */
    private static $instance = null;

    public function __construct()
    {
        $this->writer = new ExposureWriter();
        $this->exposureCache = new ExposureCache(65536);
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
     * Initialize the provider: load config from RC into the native engine.
     */
    public function start()
    {
        if (!$this->enabled) {
            return false;
        }
        $this->checkNativeConfig();
        $this->registerShutdown();
        return true;
    }

    /**
     * Register a shutdown function to auto-flush exposure events at request end.
     */
    private function registerShutdown()
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $writer = $this->writer;
        register_shutdown_function(function () use ($writer) {
            $writer->flush();
        });
        $this->shutdownRegistered = true;
    }

    /**
     * Check if the native FFE configuration has been loaded or changed via Remote Config.
     */
    private function checkNativeConfig()
    {
        // Check if config changed since last check (covers both new config and removal)
        if (\dd_trace_internal_fn('ffe_config_changed')) {
            $hasConfig = \dd_trace_internal_fn('ffe_has_config');
            if ($hasConfig && !$this->configLoaded) {
                $this->configLoaded = true;
            } elseif (!$hasConfig && $this->configLoaded) {
                // Config was removed via RC
                $this->configLoaded = false;
                $this->exposureCache->clear();
            }
        } elseif (!$this->configLoaded && \dd_trace_internal_fn('ffe_has_config')) {
            // First check — config was already loaded before provider started
            $this->configLoaded = true;
        }
    }

    /**
     * Evaluate a feature flag using the native datadog-ffe engine.
     *
     * @param string $flagKey The flag key to evaluate
     * @param string $variationType The expected variation type (STRING, BOOLEAN, INTEGER, NUMERIC, JSON)
     * @param mixed $defaultValue The default value to return if evaluation fails
     * @param string|null $targetingKey The targeting key (user/subject ID)
     * @param array $attributes Additional context attributes
     * @return array ['value' => mixed, 'reason' => string, 'variant' => string|null, 'allocation_key' => string|null]
     */
    public function evaluate($flagKey, $variationType, $defaultValue, $targetingKey, $attributes = [])
    {
        if (!$this->enabled) {
            return ['value' => $defaultValue, 'reason' => 'DISABLED', 'variant' => null, 'allocation_key' => null];
        }

        // Ensure native config is loaded
        $this->checkNativeConfig();

        if (!$this->configLoaded) {
            return ['value' => $defaultValue, 'reason' => 'DEFAULT', 'variant' => null, 'allocation_key' => null];
        }

        $typeId = isset(self::$TYPE_MAP[$variationType]) ? self::$TYPE_MAP[$variationType] : 0;

        // Call the native evaluation engine with structured attributes
        $result = \dd_trace_internal_fn('ffe_evaluate', $flagKey, $typeId,
            $targetingKey, is_array($attributes) ? $attributes : []);

        if ($result === null) {
            return ['value' => $defaultValue, 'reason' => 'DEFAULT', 'variant' => null, 'allocation_key' => null];
        }

        $errorCode = isset($result['error_code']) ? (int)$result['error_code'] : 0;
        $reason = isset($result['reason']) ? (int)$result['reason'] : 1;
        $reasonStr = isset(self::$REASON_MAP[$reason]) ? self::$REASON_MAP[$reason] : 'DEFAULT';

        // Error or no variant → return default
        if ($errorCode !== 0 || $result['variant'] === null) {
            return ['value' => $defaultValue, 'reason' => $reasonStr, 'variant' => null, 'allocation_key' => null];
        }

        // Parse the value from JSON
        $value = $this->parseNativeValue($result['value_json'], $variationType, $defaultValue);

        // Report exposure event (deduplicated via ExposureCache)
        $doLog = !empty($result['do_log']);
        if ($doLog && $result['variant'] !== null && $result['allocation_key'] !== null) {
            $this->reportExposure(
                $flagKey,
                $result['variant'],
                $result['allocation_key'],
                $targetingKey,
                $attributes
            );
        }

        return [
            'value' => $value,
            'reason' => $reasonStr,
            'variant' => $result['variant'],
            'allocation_key' => $result['allocation_key'],
        ];
    }

    /**
     * Parse a native value JSON string into the correct PHP type.
     */
    private function parseNativeValue($valueJson, $variationType, $defaultValue)
    {
        if ($valueJson === null || $valueJson === 'null') {
            return $defaultValue;
        }

        switch ($variationType) {
            case 'BOOLEAN':
                return $valueJson === 'true';
            case 'INTEGER':
                return (int)$valueJson;
            case 'NUMERIC':
                return (float)$valueJson;
            case 'JSON':
                $decoded = json_decode($valueJson, true);
                return $decoded !== null ? $decoded : $defaultValue;
            case 'STRING':
            default:
                // String values come as JSON-encoded strings (with quotes)
                $decoded = json_decode($valueJson);
                return is_string($decoded) ? $decoded : $valueJson;
        }
    }

    /**
     * Report a feature flag exposure event, deduplicated via ExposureCache.
     */
    private function reportExposure($flagKey, $variantKey, $allocationKey, $targetingKey, $attributes)
    {
        if (!$variantKey || !$allocationKey) {
            return;
        }

        $subjectId = $targetingKey !== null ? $targetingKey : '';

        // add() returns true for new events or when value changed, false for exact duplicates
        if (!$this->exposureCache->add($flagKey, $subjectId, $variantKey, $allocationKey)) {
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
     * Check if config has been loaded into the native engine.
     */
    public function isReady()
    {
        return $this->configLoaded;
    }
}
