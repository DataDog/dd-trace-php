<?php

namespace DDTrace\OpenTracer;

use DDTrace\Exceptions\InvalidSpanArgument;
use DDTrace\SpanContext;
use DDTrace\Tags;
use DDTrace\Contracts\Span as SpanInterface;
use OpenTracing\Span as OpenTracingSpan;
use Throwable;

final class Span implements SpanInterface
{
    /**
     * @var OpenTracingSpan
     */
    private $span;

    /**
     * @var SpanContext
     */
    private $context;

    /**
     * @var bool
     */
    private $hasError = false;

    /**
     * @var bool
     */
    private $isFinished = false;

    /**
     * @param OpenTracingSpan $span
     */
    public function __construct(OpenTracingSpan $span)
    {
        $this->span = $span;
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName($operationName)
    {
        $this->span->overwriteOperationName($operationName);
    }

    /**
     * {@inheritdoc}
     */
    public function setTag($key, $value)
    {
        $this->span->setTag($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getTag($key)
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function setError($error)
    {
        if (true === $this->isFinished) {
            return;
        }
        if ($error instanceof Throwable) {
            $this->hasError = true;
            $this->setTag(Tags\ERROR_MSG, $error->getMessage());
            $this->setTag(Tags\ERROR_TYPE, get_class($error));
            $this->setTag(Tags\ERROR_STACK, $error->getTraceAsString());
            return;
        }
        if (is_bool($error)) {
            $this->hasError = $error;
            return;
        }
        if (null === $error) {
            $this->hasError = false;
            return;
        }
        throw InvalidSpanArgument::forError($error);
    }

    /**
     * {@inheritdoc}
     */
    public function setRawError($message, $type)
    {
        if (true === $this->isFinished) {
            return;
        }
        $this->hasError = true;
        $this->setTag(Tags\ERROR_MSG, $message);
        $this->setTag(Tags\ERROR_TYPE, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null)
    {
        $this->isFinished = true;
        $this->span->finish($finishTime);
    }

    /**
     * {@inheritdoc}
     */
    public function getOperationName()
    {
        return $this->span->getOperationName();
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        if (isset($this->context)) {
            return $this->context;
        }
        return $this->context = new SpanContext(
            $this->getTraceId(),
            $this->getSpanId(),
            $this->getParentId(),
            $this->getAllBaggageItems(),
            $this->isDistributedTracingActivationContext()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function log(array $fields = [], $timestamp = null)
    {
        $this->span->log($fields, $timestamp);
    }

    /**
     * {@inheritdoc}
     */
    public function addBaggageItem($key, $value)
    {
        $this->span->addBaggageItem($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return $this->span->getBaggageItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getStartTime()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getDuration()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getService()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function isFinished()
    {
        return $this->isFinished;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTags()
    {
        return [];
    }
}
