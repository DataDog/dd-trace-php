<?php

namespace DDTrace\Data;

use DDTrace\Time;
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
        } elseif ($name == "tags") {
            $name = "meta";
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
