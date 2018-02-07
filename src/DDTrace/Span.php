<?php

namespace DDTrace;

use Exception;
use InvalidArgumentException;
use Throwable;

final class Span
{
    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * The unique integer (64-bit unsigned) ID of the trace containing this span.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    private $traceId;

    /**
     * The span integer ID of the parent span.
     *
     * @var string
     */
    private $parentId;

    /**
     * The span integer (64-bit unsigned) ID.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    private $spanId;

    /**
     * Name is the name of the operation being measured. Some examples
     * might be "http.handler", "fileserver.upload" or "video.decompress".
     * Name should be set on every span.
     *
     * @var string
     */
    private $name;

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
     * @var string
     */
    private $type;

    /**
     * @var int
     */
    private $start;

    /**
     * @var int|null
     */
    private $duration;

    /**
     * @var array
     */
    private $meta = [];

    /**
     * @var bool
     */
    private $hasError = false;

    public function __construct(
        Tracer $tracer,
        $name,
        $service,
        $resource,
        $traceId,
        $spanId,
        $parentId = null,
        $start = null
    ) {
        $this->tracer = $tracer;
        $this->name = (string) $name;
        $this->service = $service;
        $this->resource = (string) $resource;
        $this->start = $start ?: MicroTime\now();
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId;
    }

    /**
     * @return string
     */
    public function getTraceId()
    {
        return $this->traceId;
    }

    /**
     * @return string
     */
    public function getSpanId()
    {
        return $this->spanId;
    }

    /**
     * @return null|string
     */
    public function getParentId()
    {
        return $this->parentId;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return void
     */
    public function setName($name)
    {
        $this->name = $name;
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
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Adds an arbitrary meta field to the current Span.
     * If the Span has been finished, it will not be modified by the method.
     *
     * @param string $key
     * @param string $value
     * @throws InvalidArgumentException
     */
    public function setMeta($key, $value)
    {
        if ($this->isFinished()) {
            return;
        }

        if ($key !== (string) $key) {
            throw new InvalidArgumentException(
                sprintf('First argument expected to be string, got %s', gettype($key))
            );
        }

        $this->meta[$key] = (string) $value;
    }

    /**
     * @param string $key
     * @return string|null
     */
    public function getMeta($key)
    {
        if (array_key_exists($key, $this->meta)) {
            return $this->meta[$key];
        }

        return null;
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
            $this->setMeta(Meta\ERROR_MSG_KEY, $e->getMessage());
            $this->setMeta(Meta\ERROR_TYPE_KEY, get_class($e));
            $this->setMeta(Meta\ERROR_STACK_KEY, $e->getTraceAsString());
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
     * Finish closes this Span (but not its children) providing the duration
     * of this part of the tracing session. This method is idempotent so
     * calling this method multiple times is safe and doesn't update the
     * current Span. Once a Span has been finished, methods that modify the Span
     * will become no-ops.
     *
     * @param int|null $finish
     * @return void
     */
    public function finish($finish = null)
    {
        if ($this->isFinished()) {
            return;
        }

        $this->duration = ($finish ?: MicroTime\now()) - $this->start;
        $this->tracer->record($this);
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

    private function isFinished()
    {
        return $this->duration !== null;
    }
}
