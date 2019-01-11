<?php

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Exceptions\InvalidSpanArgument;
use Exception;
use InvalidArgumentException;
use Throwable;

final class Span implements SpanInterface
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
     * @var SpanContextInterface
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
     * @param SpanContextInterface $context
     * @param string $service
     * @param string $resource
     * @param int|null $startTime
     */
    public function __construct(
        $operationName,
        SpanContextInterface $context,
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
        return $this->context->getTraceId();
    }

    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
        return $this->context->getSpanId();
    }

    /**
     * {@inheritdoc}
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
    public function setTag($key, $value)
    {
        if ($this->isFinished()) {
            return;
        }

        if ($key !== (string)$key) {
            throw InvalidSpanArgument::forTagKey($key);
        }

        if ($key === Tag::ERROR) {
            $this->setError($value);
            return;
        }

        if ($key === Tag::SERVICE_NAME) {
            $this->service = $value;
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
     * @deprecated
     * @param string $resource
     */
    public function setResource($resource)
    {
        error_log('DEPRECATED: Method "DDTrace\Span\setResource" will be removed soon, '
            . 'you should use DDTrace\Span::setTag(Tag::RESOURCE_NAME, $value) instead.');
        $this->setTag(Tag::RESOURCE_NAME, $resource);
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
        if ($this->isFinished()) {
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
        if ($this->isFinished()) {
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
        return $this->context->getAllBaggageItems();
    }
}
