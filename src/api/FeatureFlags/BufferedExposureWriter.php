<?php

namespace DDTrace\FeatureFlags;

final class BufferedExposureWriter implements ExposureWriter
{
    private $sink;
    private $maxBatchSize;
    private $buffer = array();
    private $dedup = array();

    public function __construct($sink, $maxBatchSize = 100)
    {
        if (!is_callable($sink)) {
            throw new \InvalidArgumentException('Expected an exposure sink callable');
        }

        if (!is_int($maxBatchSize) || $maxBatchSize < 1) {
            throw new \InvalidArgumentException('Exposure batch size must be a positive integer');
        }

        $this->sink = $sink;
        $this->maxBatchSize = $maxBatchSize;
    }

    public function write(array $event)
    {
        if (array_key_exists('doLog', $event) && $event['doLog'] === false) {
            return;
        }

        $dedupKey = $this->dedupKey($event);
        $stateKey = $this->stateKey($event);
        if (isset($this->dedup[$dedupKey]) && $this->dedup[$dedupKey] === $stateKey) {
            return;
        }

        $this->dedup[$dedupKey] = $stateKey;
        $this->buffer[] = $event;

        if (count($this->buffer) >= $this->maxBatchSize) {
            $this->flush();
        }
    }

    public function flush()
    {
        if (!$this->buffer) {
            return;
        }

        $batch = $this->buffer;
        $this->buffer = array();

        call_user_func($this->sink, $batch);
    }

    public function getBufferedCount()
    {
        return count($this->buffer);
    }

    private function dedupKey(array $event)
    {
        return $this->eventValue($event, 'flagKey') . "\0" . $this->eventValue($event, 'targetingKey');
    }

    private function stateKey(array $event)
    {
        return $this->eventValue($event, 'allocationKey') . "\0" . $this->eventValue($event, 'variant');
    }

    private function eventValue(array $event, $key)
    {
        if (!array_key_exists($key, $event) || $event[$key] === null) {
            return '';
        }

        if (is_scalar($event[$key])) {
            return (string) $event[$key];
        }

        return json_encode($event[$key]);
    }
}
