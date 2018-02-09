<?php

namespace DDTrace;

use Exception;
use InvalidArgumentException;
use OpenTracing\SpanContext as OpenTracingContext;
use Throwable;
use OpenTracing\Span as OpenTracingSpan;

final class Span implements OpenTracingSpan
{
    /**
     * Name is the name of the operation being measured. Some examples
     * might be "http.handler", "fileserver.upload" or "video.decompress".
     * Name should be set on every span.
     *
     * @var string
     */
    private $operationName;

    /**
     * @var OpenTracingContext
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
     * @param int|null $start
     */
    public function __construct(
        $operationName,
        SpanContext $context,
        $service,
        $resource,
        $start = null
    ) {
        $this->context = $context;
        $this->operationName = (string) $operationName;
        $this->service = (string) $service;
        $this->resource = (string) $resource;
        $this->startTime = $start ?: Time\now();
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
    public function setTags(array $tags)
    {
        if ($this->isFinished()) {
            return;
        }

        foreach ($tags as $key => $value) {
            if ($key !== (string) $key) {
                throw new InvalidArgumentException(
                    sprintf('First argument expected to be string, got %s', gettype($key))
                );
            }

            $this->tags[$key] = (string) $value;
        }
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

    /**
     * Stores a Throwable object within the span meta. The error status is
     * updated and the error.Error() string is included with a default meta key.
     * If the Span has been finished, it will not be modified by this method.
     *
     * @param Throwable|Exception $e
     * @throws InvalidArgumentException
     */
    public function setError($e)
    {
        if ($this->isFinished()) {
            return;
        }

        if (($e instanceof Exception) || ($e instanceof Throwable)) {
            $this->hasError = true;
            $this->setTags([
                Tags\ERROR_MSG_KEY => $e->getMessage(),
                Tags\ERROR_TYPE_KEY => get_class($e),
                Tags\ERROR_STACK_KEY => $e->getTraceAsString(),
            ]);

            return;
        }

        throw new InvalidArgumentException(
            sprintf('Error should be either Exception or Throwable, got %s.', gettype($e))
        );
    }

    public function hasError()
    {
        return $this->hasError;
    }

    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null, array $logRecords = [])
    {
        if ($this->isFinished()) {
            return;
        }

        $this->duration = ($finishTime ?: Time\now()) - $this->startTime;
    }

    /**
     * @param Throwable|Exception $e
     * @return void
     */
    public function finishWithError($e)
    {
        $this->setError($e);
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
        // TODO: Implement log() method.
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
