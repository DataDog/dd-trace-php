<?php

namespace DDTrace;

use DDTrace\OpenTracing\Span as OpenTracingSpan;

/**
 * An interface that defines the public operations of a Datadog Span, which enhances the OpenTracing Span api.
 */
interface SpanInterface extends OpenTracingSpan
{
    /**
     * Stores a Throwable object within the span tags. The error status is
     * updated and the error.Error() string is included with a default tag key.
     * If the Span has been finished, it will not be modified by this method.
     *
     * @param \Throwable|\Exception|bool|string|null $error
     * @throws \InvalidArgumentException
     */
    public function setError($error);

    /**
     * Stores an error message and type in the Span.
     *
     * @param string $message
     * @param string $type
     */
    public function setRawError($message, $type);

    /**
     * Tells whether or not this Span contains errors.
     *
     * @return bool
     */
    public function hasError();
}
