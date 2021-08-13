<?php

namespace DDTrace;

use DDTrace\Data\Span as DataSpan;
use DDTrace\Exceptions\InvalidSpanArgument;
use DDTrace\SpanContext as SpanContext;
use DDTrace\Http\Urls;
use DDTrace\Processing\TraceAnalyticsProcessor;
use Exception;
use InvalidArgumentException;
use Throwable;

if (PHP_VERSION_ID < 70000) {
    final class Span extends DataSpan
    {
        private static $metricNames = [Tag::ANALYTICS_KEY => true];
        // associative array for quickly checking if tag has special meaning, should include metric_names
        private static $specialTags = [
            Tag::ANALYTICS_KEY => true,
            Tag::ERROR => true,
            Tag::ERROR_MSG => true,
            Tag::SERVICE_NAME => true,
            Tag::RESOURCE_NAME => true,
            Tag::SPAN_TYPE => true,
            Tag::HTTP_URL => true,
            Tag::HTTP_STATUS_CODE => true,
            Tag::MANUAL_KEEP => true,
            Tag::MANUAL_DROP => true,
            Tag::SERVICE_VERSION => true,
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
            $this->resource = null === $resource ? null : (string)$resource;
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

                if ($key === Tag::ERROR_MSG) {
                    $this->tags[$key] = (string)$value;
                    $this->setError(true);
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

                if ($key === Tag::SERVICE_VERSION) {
                    // Also set `version` tag (we want both)
                    $this->setTag(Tag::VERSION, $value);
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
            // Sync with span ID stack at the C level
            dd_trace_pop_span_id();
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
                    // We recently changed our span behavior:
                    // when we set an error message, we now mark the span as 'error'.
                    // In order to be backward compatible with this publicly exposed method we manually set the message,
                    // and not the errror, internally.
                    // This should be considered a broken behavior because it would not allow for users to log multiple
                    // messages, and logging multiple messages is not prohibited by the OpenTracing spec:
                    // https://opentracing.io/docs/overview/tags-logs-baggage/#logs
                    // We want to deprecate this behavior and change it. In the meantime we apply this workaround.
                    $this->tags[Tag::ERROR_MSG] = (string)$value;
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
         * @param bool $value
         * @return self
         * @deprecated
         */
        public function setTraceAnalyticsCandidate($value = true)
        {
            return $this;
        }

        /**
         * @return bool
         * @deprecated
         */
        public function isTraceAnalyticsCandidate()
        {
            return false;
        }
    }
} else {
    class Span extends DataSpan
    {
        private static $metricNames = [ Tag::ANALYTICS_KEY => true ];
        // associative array for quickly checking if tag has special meaning, should include metric_names
        private static $specialTags = [
            Tag::ANALYTICS_KEY => true,
            Tag::ERROR => true,
            Tag::ERROR_MSG => true,
            Tag::SERVICE_NAME => true,
            Tag::RESOURCE_NAME => true,
            Tag::SPAN_TYPE => true,
            Tag::HTTP_URL => true,
            Tag::HTTP_STATUS_CODE => true,
            Tag::MANUAL_KEEP => true,
            Tag::MANUAL_DROP => true,
            Tag::SERVICE_VERSION => true,
        ];

        /**
         * Span constructor.
         * @param SpanData $internalSpan
         * @param SpanContext $context
         */
        public function __construct(SpanData $internalSpan, SpanContext $context)
        {
            $this->internalSpan = $internalSpan;
            $this->context = $context;
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
            $this->internalSpan->name = $operationName;
        }

        /**
         * {@inheritdoc}
         */
        public function getResource()
        {
            return $this->internalSpan->resource;
        }

        /**
         * {@inheritdoc}
         */
        public function getService()
        {
            return $this->internalSpan->service;
        }

        /**
         * {@inheritdoc}
         */
        public function getType()
        {
            return $this->internalSpan->type;
        }

        /**
         * {@inheritdoc}
         */
        public function getStartTime()
        {
            // internally we have nanoseconds, but we'll expose microseconds here
            return (int) ($this->startTime / 1000);
        }

        /**
         * {@inheritdoc}
         */
        public function getDuration()
        {
            // internally we have nanoseconds, but we'll expose microseconds here
            return (int) ($this->duration / 1000);
        }

        /**
         * {@inheritdoc}
         */
        public function setTag($key, $value, $setIfFinished = false)
        {
            if ($this->isFinished() && !$setIfFinished) { // if finished
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

                if ($key === Tag::ERROR_MSG) {
                    $this->internalSpan->meta[$key] = (string)$value;
                    $this->setError(true);
                    return;
                }

                if ($key === Tag::SERVICE_NAME) {
                    $this->internalSpan->service = $value;
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
                    $this->internalSpan->resource = (string)$value;
                    return;
                }

                if ($key === Tag::SPAN_TYPE) {
                    $this->internalSpan->type = $value;
                    return;
                }

                if ($key === Tag::HTTP_URL) {
                    $value = Urls::sanitize((string)$value);
                }

                if ($key === Tag::HTTP_STATUS_CODE && $value >= 500) {
                    $this->hasError = true;
                    if (!isset($this->internalSpan->meta[Tag::ERROR_TYPE])) {
                        $this->internalSpan->meta[Tag::ERROR_TYPE] = 'Internal Server Error';
                    }
                }

                if ($key === Tag::SERVICE_VERSION) {
                    // Also set `version` tag (we want both)
                    $this->setTag(Tag::VERSION, $value);
                }

                if (array_key_exists($key, self::$metricNames)) {
                    $this->setMetric($key, $value);
                    return;
                }
            }

            $this->internalSpan->meta[$key] = (string)$value;
        }

        /**
         * {@inheritdoc}
         */
        public function getTag($key)
        {
            if (isset($this->internalSpan->meta) && array_key_exists($key, $this->internalSpan->meta)) {
                return $this->internalSpan->meta[$key];
            }

            return null;
        }

        /**
         * {@inheritdoc}
         */
        public function getAllTags()
        {
            return isset($this->internalSpan->meta) ? $this->internalSpan->meta : [];
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
                TraceAnalyticsProcessor::normalizeAnalyticsValue($this->internalSpan->metrics, $value);
                return;
            }

            $this->internalSpan->metrics[$key] = $value;
        }

        /**
         * @return array
         */
        public function getMetrics()
        {
            return isset($this->internalSpan->metrics) ? $this->internalSpan->metrics : [];
        }

        /**
         * {@inheritdoc}
         */
        public function setResource($resource)
        {
            $this->internalSpan->resource = (string)$resource;
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
            if (($error instanceof Exception) || ($error instanceof Throwable)) {
                $this->hasError = true;
                $this->internalSpan->meta[Tag::ERROR_MSG] = $error->getMessage();
                $this->internalSpan->meta[Tag::ERROR_TYPE] = get_class($error);
                $this->internalSpan->meta[Tag::ERROR_STACK] = $error->getTraceAsString();
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
            $this->hasError = true;
            $this->internalSpan->meta[Tag::ERROR_MSG] = $message;
            $this->internalSpan->meta[Tag::ERROR_TYPE] = $type;
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
            if (!$this->isFinished()) {
                // @phpstan-ignore-next-line
                close_span($finishTime);
            }
        }

        /**
         * {@inheritdoc}
         */
        public function isFinished()
        {
            return $this->duration !== 0;
        }

        /**
         * {@inheritdoc}
         */
        public function getOperationName()
        {
            return $this->internalSpan->name;
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
                    // We recently changed our span behavior:
                    // when we set an error message, we now mark the span as 'error'.
                    // In order to be backward compatible with this publicly exposed method we manually set the message,
                    // and not the errror, internally.
                    // This should be considered a broken behavior because it would not allow for users to log multiple
                    // messages, and logging multiple messages is not prohibited by the OpenTracing spec:
                    // https://opentracing.io/docs/overview/tags-logs-baggage/#logs
                    // We want to deprecate this behavior and change it. In the meantime we apply this workaround.
                    $this->internalSpan->meta[Tag::ERROR_MSG] = (string)$value;
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
         * @deprecated
         * @param bool $value
         * @return self
         */
        public function setTraceAnalyticsCandidate($value = true)
        {
            return $this;
        }

        /**
         * @deprecated
         * @return bool
         */
        public function isTraceAnalyticsCandidate()
        {
            return false;
        }
    }
}
