<?php

namespace DDTrace\Data;

use DDTrace\Data\SpanContext as SpanContextData;
use DDTrace\Contracts\Span as SpanInterface;

/**
 * Class Span
 * @property string $operationName
 * @property string $resource
 * @property string $service
 * @property string $type
 * @property int $startTime
 * @property int $duration
 * @property array $tags
 * @property array $metrics
 * @property array $baggage
 */
abstract class Span implements SpanInterface
{
    /**
     * @var SpanContextData
     */
    public $context;

    /**
     * @var bool
     */
    public $hasError = false;

    /**
     * @var \DDTrace\SpanData
     */
    protected $internalSpan;

    public function &__get($name)
    {
        if ($name == "operationName") {
            $name = "name";
        } elseif ($name == "tags" || $name == "metrics") {
            // A by-value read yields an array; the by-ref fetch below would yield the SpanTagsView.
            $tags = $name == "tags" ? $this->internalSpan->meta : $this->internalSpan->metrics;
            return $tags;
        } elseif ($name == "duration") {
            $duration = $this->internalSpan->getDuration();
            return $duration;
        } elseif ($name == "startTime") {
            $startTime = $this->internalSpan->getStartTime();
            return $startTime;
        }
        return $this->internalSpan->$name;
    }

    public function __set($name, $value)
    {
        if ($name == "operationName") {
            $name = "name";
        } elseif ($name == "tags") {
            $name = "meta";
        }
        return $this->internalSpan->$name = $value;
    }

    public function __isset($name)
    {
        return $this->__get($name) !== null;
    }
}
