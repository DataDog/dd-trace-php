<?php

namespace DDTrace\Data;

use DDTrace\Time;
use DDTrace\Data\SpanContext as SpanContextData;
use DDTrace\Contracts\Span as SpanInterface;

if (PHP_VERSION_ID < 70000) {
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
         * Note that resource name will be inherited from parent if its not set.
         *
         * @var string
         */
        public $resource;

        /**
         * Service is the name of the process doing a particular job. Some
         * examples might be "user-database" or "datadog-web-app".
         *
         * Note that service name will be inherited from parent if its not set.
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
         * @var array
         */
        public $metrics = [];

        /**
         * @var bool Whether or not this trace can be even considered for trace analytics automatic configuration.
         */
        public $isTraceAnalyticsCandidate = false;
    }
} else {
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
                // @phpstan-ignore-next-line
                $duration = $this->internalSpan->getDuration();
                return $duration;
            } elseif ($name == "startTime") {
                // @phpstan-ignore-next-line
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
}
