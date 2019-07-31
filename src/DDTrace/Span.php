<?php

namespace DDTrace;

use DDTrace\Integrations\Integration;
use DDTrace\Data\Span as DataSpan;

use DDTrace\Exceptions\InvalidSpanArgument;
use DDTrace\SpanContext as SpanContext;
use DDTrace\Http\Urls;
use DDTrace\Processing\TraceAnalyticsProcessor;
use Exception;
use InvalidArgumentException;
use Throwable;

final class Span extends DataSpan
{
    private static $metricNames = [ Tag::ANALYTICS_KEY => true ];
    // associative array for quickly checking if tag has special meaning, should include metric_names
    private static $specialTags = [
        Tag::ANALYTICS_KEY => true,
        Tag::ERROR => true,
        Tag::SERVICE_NAME => true,
        Tag::RESOURCE_NAME => true,
        Tag::SPAN_TYPE => true,
        Tag::HTTP_URL => true,
        Tag::HTTP_STATUS_CODE => true,
        Tag::MANUAL_KEEP => true,
        Tag::MANUAL_DROP => true,
    ];

    /**
     * Span constructor.
     * @param string $operationName
     * @param SpanContext $context
     * @param string $service
     * @param string $resource
     * @param int|null $startTime
     */
    public function __construct(
        $operationName,
        SpanContext $context,
        $service,
        $resource,
        $startTime = null
    ) {
        $this->context = $context;
        $this->operationName = (string)$operationName;
        $this->service = (string)$service;
        $this->resource = (string)$resource;
        $this->startTime = $startTime ?: Time::now();
    }

    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
        return $this->context->traceId;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
        return $this->context->spanId;
    }

    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
        return $this->context->parentId;
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName($operationName)
    {
        $this->operationName = $operationName;
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * {@inheritdoc}
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * {@inheritdoc}
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * {@inheritdoc}
     */
    public function setTag($key, $value, $setIfFinished = false)
    {
        if ($this->duration !== null && !$setIfFinished) { // if finished
            return;
        }
        if ($key !== (string)$key) {
            throw InvalidSpanArgument::forTagKey($key);
        }
        // Since sub classes can change the return value of a known method,
        // we quietly ignore values that could cause errors when converting to string
        if (is_object($value) && $key !== Tag::ERROR) {
            return;
        }

        if (array_key_exists($key, self::$specialTags)) {
            if ($key === Tag::ERROR) {
                $this->setError($value);
                return;
            }

            if ($key === Tag::SERVICE_NAME) {
                $this->service = $value;
                return;
            }

            if ($key === Tag::MANUAL_KEEP) {
                GlobalTracer::get()->setPrioritySampling(Sampling\PrioritySampling::USER_KEEP);
                return;
            }

            if ($key === Tag::MANUAL_DROP) {
                GlobalTracer::get()->setPrioritySampling(Sampling\PrioritySampling::USER_REJECT);
                return;
            }

            if ($key === Tag::RESOURCE_NAME) {
                $this->resource = (string)$value;
                return;
            }

            if ($key === Tag::SPAN_TYPE) {
                $this->type = $value;
                return;
            }

            if ($key === Tag::HTTP_URL) {
                $value = Urls::sanitize((string)$value);
            }

            if ($key === Tag::HTTP_STATUS_CODE && $value >= 500) {
                $this->hasError = true;
                if (!isset($this->tags[Tag::ERROR_TYPE])) {
                    $this->tags[Tag::ERROR_TYPE] = 'Internal Server Error';
                }
            }

            if (array_key_exists($key, self::$metricNames)) {
                $this->setMetric($key, $value);
                return;
            }
        }

        $this->tags[$key] = (string)$value;
    }

    /**
     * {@inheritdoc}
     */
    public function getTag($key)
    {
        if (array_key_exists($key, $this->tags)) {
            return $this->tags[$key];
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTags()
    {
        return $this->tags;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTag($name)
    {
        return array_key_exists($name, $this->getAllTags());
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function setMetric($key, $value)
    {
        if ($key === Tag::ANALYTICS_KEY) {
            TraceAnalyticsProcessor::normalizeAnalyticsValue($this->metrics, $value);
            return;
        }

        $this->metrics[$key] = $value;
    }

    /**
     * @return array
     */
    public function getMetrics()
    {
        return $this->metrics;
    }

    /**
     * {@inheritdoc}
     */
    public function setResource($resource)
    {
        $this->resource = (string)$resource;
    }

    /**
     * Stores a Throwable object within the span tags. The error status is
     * updated and the error.Error() string is included with a default tag key.
     * If the Span has been finished, it will not be modified by this method.
     *
     * @param Throwable|Exception|bool|string|null $error
     * @throws InvalidArgumentException
     */
    public function setError($error)
    {
        if ($this->duration !== null) { // if finished
            return;
        }

        if (($error instanceof Exception) || ($error instanceof Throwable)) {
            $this->hasError = true;
            $this->tags[Tag::ERROR_MSG] = $error->getMessage();
            $this->tags[Tag::ERROR_TYPE] = get_class($error);
            $this->tags[Tag::ERROR_STACK] = $error->getTraceAsString();
            return;
        }

        if (is_bool($error)) {
            $this->hasError = $error;
            return;
        }

        if (is_null($error)) {
            $this->hasError = false;
        }

        throw InvalidSpanArgument::forError($error);
    }

    /**
     * @param string $message
     * @param string $type
     */
    public function setRawError($message, $type)
    {
        if ($this->duration !== null) { // if finished
            return;
        }

        $this->hasError = true;
        $this->tags[Tag::ERROR_MSG] = $message;
        $this->tags[Tag::ERROR_TYPE] = $type;
    }

    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null)
    {
        if ($this->duration !== null) { // if finished
            return;
        }

        $this->duration = ($finishTime ?: Time::now()) - $this->startTime;
    }

    /**
     * @param Throwable|Exception $error
     * @return void
     */
    public function finishWithError($error)
    {
        $this->setError($error);
        $this->finish();
    }

    /**
     * {@inheritdoc}
     */
    public function isFinished()
    {
        return $this->duration !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function getOperationName()
    {
        return $this->operationName;
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * {@inheritdoc}
     */
    public function log(array $fields = [], $timestamp = null)
    {
        foreach ($fields as $key => $value) {
            if ($key === Tag::LOG_EVENT && $value === Tag::ERROR) {
                $this->setError(true);
            } elseif ($key === Tag::LOG_ERROR || $key === Tag::LOG_ERROR_OBJECT) {
                $this->setError($value);
            } elseif ($key === Tag::LOG_MESSAGE) {
                $this->setTag(Tag::ERROR_MSG, $value);
            } elseif ($key === Tag::LOG_STACK) {
                $this->setTag(Tag::ERROR_STACK, $value);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addBaggageItem($key, $value)
    {
        $this->context = $this->context->withBaggageItem($key, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return $this->context->getBaggageItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
        return $this->context->baggageItems;
    }

    /**
     * {@inheritdoc}
     *
     * @param Integration $integration
     * @return self
     */
    public function setIntegration(Integration $integration)
    {
        $this->integration = $integration;
        return $this;
    }

    /**
     * @return null|Integration
     */
    public function getIntegration()
    {
        return $this->integration;
    }

    /**
     * @param bool $value
     * @return self
     */
    public function setTraceAnalyticsCandidate($value = true)
    {
        $this->isTraceAnalyticsCandidate = $value;
        return $this;
    }

    /**
     * @return bool
     */
    public function isTraceAnalyticsCandidate()
    {
        return $this->isTraceAnalyticsCandidate;
    }
}
