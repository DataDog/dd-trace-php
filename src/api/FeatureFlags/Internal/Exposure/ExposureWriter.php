<?php

namespace DDTrace\FeatureFlags\Internal\Exposure;

use DDTrace\FeatureFlags\Internal\EvaluationCompleted;

final class ExposureWriter
{
    const DEFAULT_CACHE_CAPACITY = 65536;
    const DEFAULT_BUFFER_LIMIT = 1000;

    private $transport;
    private $context;
    private $cacheCapacity;
    private $bufferLimit;
    private $cache = array();
    private $lru = array();
    private $buffer = array();
    private $dropped = 0;

    public function __construct(
        ExposureTransport $transport,
        array $context = array(),
        $cacheCapacity = self::DEFAULT_CACHE_CAPACITY,
        $bufferLimit = self::DEFAULT_BUFFER_LIMIT
    ) {
        $this->transport = $transport;
        $this->context = $context;
        $this->cacheCapacity = max(0, (int) $cacheCapacity);
        $this->bufferLimit = max(0, (int) $bufferLimit);
    }

    public static function createDefault()
    {
        return new self(new AgentExposureTransport(), self::defaultContext());
    }

    public function record(EvaluationCompleted $evaluation)
    {
        if (!$evaluation->shouldLogExposure()) {
            return false;
        }

        $allocationKey = $evaluation->getAllocationKey();
        $variant = $evaluation->getVariant();
        if ($allocationKey === null || !is_string($variant) || $variant === '') {
            return false;
        }

        $targetingKey = $evaluation->getTargetingKey();
        $subjectId = $targetingKey === null ? '' : $targetingKey;
        $cacheKey = $evaluation->getFlagKey() . "\0" . $subjectId;
        $cacheValue = $allocationKey . "\0" . $variant;

        if ($this->isDuplicate($cacheKey, $cacheValue)) {
            return false;
        }

        if (count($this->buffer) >= $this->bufferLimit) {
            $this->dropped++;
            return false;
        }

        $this->remember($cacheKey, $cacheValue);

        $this->buffer[] = array(
            'timestamp' => (int) floor(microtime(true) * 1000),
            'allocation' => array('key' => $allocationKey),
            'flag' => array('key' => $evaluation->getFlagKey()),
            'variant' => array('key' => $variant),
            'subject' => array(
                'id' => $subjectId,
                'attributes' => $evaluation->getAttributes(),
            ),
        );

        return true;
    }

    public function flush()
    {
        if (!$this->buffer) {
            return true;
        }

        $events = $this->buffer;
        $this->buffer = array();

        $payload = array('exposures' => $events);
        if ($this->context) {
            $payload['context'] = $this->context;
        }

        try {
            $sent = $this->transport->send($payload);
        } catch (\Throwable $throwable) {
            $sent = false;
        }

        if (!$sent) {
            $this->dropped += count($events);
        }

        return $sent;
    }

    public function bufferedCount()
    {
        return count($this->buffer);
    }

    public function droppedCount()
    {
        return $this->dropped;
    }

    private function isDuplicate($key, $value)
    {
        if ($this->cacheCapacity === 0) {
            return false;
        }

        if (array_key_exists($key, $this->cache)) {
            $duplicate = $this->cache[$key] === $value;
            unset($this->lru[$key]);
            $this->lru[$key] = true;

            return $duplicate;
        }

        return false;
    }

    private function remember($key, $value)
    {
        if ($this->cacheCapacity === 0) {
            return;
        }

        if (array_key_exists($key, $this->cache)) {
            unset($this->lru[$key]);
        }

        if (count($this->cache) >= $this->cacheCapacity) {
            reset($this->lru);
            $oldest = key($this->lru);
            if ($oldest !== null) {
                unset($this->lru[$oldest], $this->cache[$oldest]);
            }
        }

        $this->cache[$key] = $value;
        $this->lru[$key] = true;
    }

    private static function defaultContext()
    {
        $context = array();
        foreach (array('DD_SERVICE' => 'service', 'DD_ENV' => 'env', 'DD_VERSION' => 'version') as $env => $key) {
            $value = self::envConfig($env);
            if (is_string($value) && $value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    private static function envConfig($name)
    {
        if (function_exists('dd_trace_env_config')) {
            return \dd_trace_env_config($name);
        }

        $value = getenv($name);

        return $value === false ? '' : $value;
    }
}
