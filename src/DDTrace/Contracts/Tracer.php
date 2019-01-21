<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Tracer.php
 */

namespace DDTrace\Contracts;

use DDTrace\Exceptions\InvalidReferencesSet;
use DDTrace\Exceptions\InvalidSpanOption;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\StartSpanOptions;

interface Tracer
{
    /**
     * Returns the current {@link ScopeManager}, which may be a noop but may not be null.
     *
     * @return ScopeManager
     */
    public function getScopeManager();

    /**
     * Returns the active {@link Span}. This is a shorthand for
     * Tracer::getScopeManager()->getActive()->getSpan(),
     * and null will be returned if {@link Scope#active()} is null.
     *
     * @return Span|null
     */
    public function getActiveSpan();

    /**
     * Starts a new span that is activated on a scope manager.
     *
     * It's also possible to not finish the {@see \DDTrace\Contracts\Span} when
     * {@see \DDTrace\Contracts\Scope} context expires:
     *
     *     $scope = $tracer->startActiveSpan('...', [
     *         'finish_span_on_close' => false,
     *     ]);
     *     $span = $scope->getSpan();
     *     try {
     *         $span->setTag(Tags\HTTP_METHOD, 'GET');
     *         // ...
     *     } finally {
     *         $scope->close();
     *     }
     *     // $span->finish() is not called as part of Scope deactivation as
     *     // finish_span_on_close is false
     *
     * @param string $operationName
     * @param array|StartSpanOptions $options Same as for startSpan() with
     *     aditional option of `finish_span_on_close` that enables finishing
     *     of span whenever a scope is closed. It is true by default.
     *
     * @return Scope A Scope that holds newly created Span and is activated on
     *               a ScopeManager.
     */
    public function startActiveSpan($operationName, $options = []);

    /**
     * Starts and returns a new span representing a unit of work.
     *
     * Whenever `child_of` reference is not passed then
     * {@see \DDTrace\Contracts\ScopeManager::getActive()} span is used as `child_of`
     * reference. In order to ignore implicit parent span pass in
     * `ignore_active_span` option set to true.
     *
     * Starting a span with explicit parent:
     *
     *     $tracer->startSpan('...', [
     *         'child_of' => $parentSpan,
     *     ]);
     *
     * @see \DDTrace\StartSpanOptions
     *
     * @param string $operationName
     * @param array|StartSpanOptions $options See StartSpanOptions for
     *                                        available options.
     *
     * @return Span
     *
     * @throws InvalidSpanOption for invalid option
     * @throws InvalidReferencesSet for invalid references set
     */
    public function startSpan($operationName, $options = []);

    /**
     * @param SpanContext $spanContext
     * @param string $format
     * @param mixed $carrier
     *
     * @see Formats
     *
     * @throws UnsupportedFormat when the format is not recognized by the tracer
     * implementation
     */
    public function inject(SpanContext $spanContext, $format, &$carrier);

    /**
     * @param string $format
     * @param mixed $carrier
     * @return SpanContext|null
     *
     * @see Formats
     *
     * @throws UnsupportedFormat when the format is not recognized by the tracer
     * implementation
     */
    public function extract($format, $carrier);

    /**
     * Allow tracer to send span data to be instrumented.
     *
     * This method might not be needed depending on the tracing implementation
     * but one should make sure this method is called after the request is delivered
     * to the client.
     *
     * As an implementor, a good idea would be to use {@see register_shutdown_function}
     * or {@see fastcgi_finish_request} in order to not to delay the end of the request
     * to the client.
     */
    public function flush();

    /**
     * @return mixed
     */
    public function getPrioritySampling();

    /**
     * This behaves just like Tracer::startActiveSpan(), but it saves the Scope instance
     * on the tracer to be accessed later by Tracer::getRootScope().
     *
     * @param string $operationName
     * @param array $options
     * @return Scope
     */
    public function startRootSpan($operationName, $options = []);

    /**
     * @return Scope|null
     */
    public function getRootScope();
}
