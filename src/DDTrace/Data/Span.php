<?php

namespace DDTrace\Data;

use DDTrace\Time;
use DDTrace\Data\SpanContext as SpanContextData;
use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Integrations\Integration;

abstract class Span implements SpanInterface
{
    /**
     * Operation Name is the name of the operation being measured. Some examples
     * might be "http.handler", "fileserver.upload" or "video.decompress".
     * Name should be set on every span.
     *
     * @var string
     */
    public $operationName;

    /**
     * @var SpanContextData
     */
    public $context;

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
    public $resource;

    /**
     * Service is the name of the process doing a particular job. Some
     * examples might be "user-database" or "datadog-web-app". Services
     * will be inherited from parents, so only set this in your app's
     * top level span.
     *
     * @var string
     */
    public $service;

    /**
     * Protocol associated with the span
     *
     * @var string|null
     */
    public $type;

    /**
     * @var int
     */
    public $startTime;

    /**
     * @var int|null
     */
    public $duration;

    /**
     * @var array
     */
    public $tags = [];

    /**
     * @var bool
     */
    public $hasError = false;

    /**
     *  @var Integration
     */
    public $integration;
    /**
     * @var array
     */
    public $metrics = [];

    /**
     * @var bool Whether or not this trace can be even considered for trace analytics automatic configuration.
     */
    public $isTraceAnalyticsCandidate = false;

    /**
     * Span constructor.
     * @param string $operationName
     * @param SpanContextData $context
     * @param string $service
     * @param string $resource
     * @param int|null $startTime
     */
    public function __construct(
        $operationName,
        SpanContextData $context,
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
}
