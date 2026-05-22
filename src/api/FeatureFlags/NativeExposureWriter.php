<?php

namespace DDTrace\FeatureFlags;

final class NativeExposureWriter implements ExposureWriter
{
    public function __construct()
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('Native FFE exposure writer is not available');
        }

        $this->setServiceContext();
    }

    public static function isAvailable()
    {
        return function_exists('DDTrace\\ffe_send_exposure')
            && function_exists('DDTrace\\ffe_set_service_context');
    }

    public static function createOrNoop()
    {
        return self::isAvailable() ? new self() : new NoopExposureWriter();
    }

    public function write(array $event)
    {
        if (!self::isAvailable()) {
            return;
        }

        if (array_key_exists('doLog', $event) && $event['doLog'] === false) {
            return;
        }

        $flagKey = $this->stringValue($event, 'flagKey');
        if ($flagKey === '') {
            return;
        }

        $variantKey = $this->stringValue($event, 'variant');
        $allocationKey = $this->nullableStringValue($event, 'allocationKey');
        $targetingKey = $this->nullableStringValue($event, 'targetingKey');
        $eventJson = $this->eventJson($event, $flagKey, $allocationKey, $targetingKey, $variantKey);
        if ($eventJson === null) {
            return;
        }

        \DDTrace\ffe_send_exposure($eventJson, $flagKey, $allocationKey, $targetingKey, $variantKey);
    }

    public function flush()
    {
        // Native exposure delivery is owned by the extension lifecycle. Calling
        // DDTrace\ffe_flush_exposures() here would drain the buffer without
        // forwarding it to the sidecar.
    }

    private function setServiceContext()
    {
        \DDTrace\ffe_set_service_context(
            $this->configValue('DD_SERVICE', 'datadog.service'),
            $this->configValue('DD_ENV', 'datadog.env'),
            $this->configValue('DD_VERSION', 'datadog.version')
        );
    }

    private function eventJson(array $event, $flagKey, $allocationKey, $targetingKey, $variantKey)
    {
        $attributes = array();
        if (array_key_exists('attributes', $event) && is_array($event['attributes'])) {
            foreach ($event['attributes'] as $key => $value) {
                if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                    $attributes[(string) $key] = $value;
                }
            }
        }

        $json = json_encode(array(
            'timestamp' => (int) floor(microtime(true) * 1000),
            'flag' => array('key' => $flagKey),
            'allocation' => array('key' => $allocationKey === null ? '' : $allocationKey),
            'variant' => array('key' => $variantKey),
            'subject' => array(
                'id' => $targetingKey === null ? '' : $targetingKey,
                'attributes' => empty($attributes) ? new \stdClass() : (object) $attributes,
            ),
        ), JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : null;
    }

    private function configValue($envName, $iniName)
    {
        if (function_exists('dd_trace_env_config')) {
            $value = \dd_trace_env_config($envName);
            if (is_scalar($value)) {
                return (string) $value;
            }
        }

        $value = ini_get($iniName);
        return is_string($value) ? $value : '';
    }

    private function stringValue(array $event, $key)
    {
        $value = $this->nullableStringValue($event, $key);
        return $value === null ? '' : $value;
    }

    private function nullableStringValue(array $event, $key)
    {
        if (!array_key_exists($key, $event) || $event[$key] === null) {
            return null;
        }

        if (is_scalar($event[$key])) {
            return (string) $event[$key];
        }

        $json = json_encode($event[$key]);
        return is_string($json) ? $json : null;
    }
}
