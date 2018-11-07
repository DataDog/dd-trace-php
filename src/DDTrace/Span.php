<?php

namespace DDTrace;

use DDTrace\Exceptions\InvalidSpanArgument;
use Exception;
use InvalidArgumentException;
use OpenTracing\Span as OpenTracingSpan;
use OpenTracing\SpanContext as OpenTracingContext;
use Throwable;

final class Span implements OpenTracingSpan
{
    /**
     * Operation Name is the name of the operation being measured. Some examples
     * might be "http.handler", "fileserver.upload" or "video.decompress".
     * Name should be set on every span.
     *
     * @var string
     */
    private $operationName;

    /**
     * @var SpanContext
     */
    private $context;

    /**
     * Resource is a query to a service. A web application might use
     * resources like "/user/{user_id}". A sql database might use resources
     * like "select * from user where id = ?".
     *
     * You can track thousands of resources (not millions or billions) so
     * prefer normalized resources like "/user/{id}" to "/user/123".
     *
     * Resources should only be set on an app's top level spans.
     *
     * @var string
     */
    private $resource;

    /**
     * Service is the name of the process doing a particular job. Some
     * examples might be "user-database" or "datadog-web-app". Services
     * will be inherited from parents, so only set this in your app's
     * top level span.
     *
     * @var string
     */
    private $service;

    /**
     * Protocol associated with the span
     *
     * @var string|null
     */
    private $type;

    /**
     * @var int
     */
    private $startTime;

    /**
     * @var int|null
     */
    private $duration;

    /**
     * @var array
     */
    private $tags = [];

    /**
     * @var bool
     */
    private $hasError = false;

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
        $this->startTime = $startTime ?: Time\now();
    }

    /**
     * @return string
     */
    public function getTraceId()
    {
        return $this->context->getTraceId();
    }

    /**
     * @return string
     */
    public function getSpanId()
    {
        return $this->context->getSpanId();
    }

    /**
     * @return null|string
     */
    public function getParentId()
    {
        return $this->context->getParentId();
    }

    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName($operationName)
    {
        $this->operationName = $operationName;
    }

    /**
     * @return string
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return string
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * @return string|null
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * {@inheritdoc}
     */
    public function setTag($key, $value)
    {
        if ($this->isFinished()) {
            return;
        }

        if ($key !== (string)$key) {
            throw InvalidSpanArgument::forTagKey($key);
        }

        if ($key === Tags\ERROR) {
            $this->setError($value);
            return;
        }

        if ($key === Tags\SERVICE_NAME) {
            $this->service = $value;
            return;
        }

        if ($key === Tags\RESOURCE_NAME) {
            $this->resource = $value;
            return;
        }

        if ($key === Tags\SPAN_TYPE) {
            $this->type = $value;
            return;
        }

        $this->tags[$key] = (string)$value;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function getTag($key)
    {
        if (array_key_exists($key, $this->tags)) {
            return $this->tags[$key];
        }

        return null;
    }

    /**
     * @return array
     */
    public function getAllTags()
    {
        return $this->tags;
    }

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
        if ($this->isFinished()) {
            return;
        }

        if (($error instanceof Exception) || ($error instanceof Throwable)) {
            $this->hasError = true;
            $this->tags[Tags\ERROR_MSG] = $error->getMessage();
            $this->tags[Tags\ERROR_TYPE] = get_class($error);
            $this->tags[Tags\ERROR_STACK] = $error->getTraceAsString();
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
        if ($this->isFinished()) {
            return;
        }

        $this->hasError = true;
        $this->tags[Tags\ERROR_MSG] = $message;
        $this->tags[Tags\ERROR_TYPE] = $type;
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
        if ($this->isFinished()) {
            return;
        }

        $this->duration = ($finishTime ?: Time\now()) - $this->startTime;
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
     * @return bool
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
            if ($key === Tags\LOG_EVENT && $value === Tags\ERROR) {
                $this->setError(true);
            } elseif ($key === Tags\LOG_ERROR || $key === Tags\LOG_ERROR_OBJECT) {
                $this->setError($value);
            } elseif ($key === Tags\LOG_MESSAGE) {
                $this->setTag(Tags\ERROR_MSG, $value);
            } elseif ($key === Tags\LOG_STACK) {
                $this->setTag(Tags\ERROR_STACK, $value);
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
}
