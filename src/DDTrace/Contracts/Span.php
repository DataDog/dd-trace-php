<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Span.php
 */

namespace DDTrace\Contracts;

interface Span
{
    /**
     * @return string
     */
    public function getOperationName();

    /**
     * Yields the SpanContext for this Span. Note that the return value of
     * Span::getContext() is still valid after a call to Span::finish(), as is
     * a call to Span::getContext() after a call to Span::finish().
     *
     * @return SpanContext
     */
    public function getContext();

    /**
     * Sets the end timestamp and finalizes Span state.
     *
     * With the exception of calls to getContext() (which are always allowed),
     * finish() must be the last call made to any span instance, and to do
     * otherwise leads to undefined behavior but not returning an exception.
     *
     * As an implementor, make sure you call {@see Tracer::deactivate()}
     * otherwise new spans might try to be child of this one.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param float|int|\DateTimeInterface|null $finishTime if passing float or int
     * it should represent the timestamp (including as many decimal places as you need)
     * @return void
     */
    public function finish($finishTime = null);

    /**
     * If the span is already finished, a warning should be logged.
     *
     * @param string $newOperationName
     */
    public function overwriteOperationName($newOperationName);

    /**
     * Adds a tag to the span.
     *
     * If there is a pre-existing tag set for key, it is overwritten.
     *
     * As an implementor, consider using "standard tags" listed in {@see \DDTrace\Tags}
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param string $key
     * @param string|bool|int|float $value
     * @return void
     */
    public function setTag($key, $value);

    /**
     * @param string $key
     * @return string|null
     */
    public function getTag($key);

    /**
     * Adds a log record to the span in key => value format, key must be a string and tag must be either
     * a string, a boolean value, or a numeric type.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param array $fields
     * @param int|float|\DateTimeInterface $timestamp
     * @return void
     */
    public function log(array $fields = [], $timestamp = null);

    /**
     * Adds a baggage item to the SpanContext which is immutable so it is required to use
     * SpanContext::withBaggageItem to get a new one.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public function addBaggageItem($key, $value);

    /**
     * @param string $key
     * @return string|null returns null when there is not a item under the provided key
     */
    public function getBaggageItem($key);

    /**
     * @return array
     */
    public function getAllBaggageItems();

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

    /**
     * @return int
     */
    public function getStartTime();

    /**
     * @return int
     */
    public function getDuration();

    /**
     * @return string
     */
    public function getTraceId();

    /**
     * @return string
     */
    public function getSpanId();

    /**
     * @return null|string
     */
    public function getParentId();

    /**
     * @return string
     */
    public function getResource();

    /**
     * @return string
     */
    public function getService();

    /**
     * @return string|null
     */
    public function getType();

    /**
     * @return bool
     */
    public function isFinished();

    /**
     * @return array
     */
    public function getAllTags();
}
