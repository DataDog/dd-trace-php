<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/GlobalTracer.php
 */

namespace DDTrace;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\OpenTracer\Tracer as OpenTracer;
use InvalidArgumentException;

final class GlobalTracer
{
    /**
     * @var TracerInterface
     */
    private static $instance;

    /**
     * GlobalTracer::set sets the [singleton] Tracer returned by get().
     * Those who use GlobalTracer (rather than directly manage a Tracer instance)
     * should call GlobalTracer::set as early as possible in bootstrap, prior to
     * start a new span. Prior to calling GlobalTracer::set, any Spans started
     * via the `Tracer::startActiveSpan` (etc) globals are noops.
     *
     * @param TracerInterface|\OpenTracing\Tracer $tracer
     *
     * @throws InvalidArgumentException
     */
    public static function set($tracer)
    {
        if ($tracer instanceof TracerInterface) {
            self::$instance = $tracer;
            return;
        }
        if ($tracer instanceof \OpenTracing\Tracer) {
            self::$instance = new OpenTracer($tracer);
            return;
        }
        throw new InvalidArgumentException(
            'Unable to set tracer singleton. Tracer must be an instance of '
            . '"\DDTrace\Contracts\Tracer" or "\OpenTracing\Tracer".'
        );
    }

    /**
     * GlobalTracer::get returns the global singleton `Tracer` implementation.
     * Before `GlobalTracer::set` is called, the `GlobalTracer::get` is a noop
     * implementation that drops all data handed to it.
     *
     * @return TracerInterface
     */
    public static function get()
    {
        if (null !== self::$instance) {
            return self::$instance;
        }
        return self::$instance = NoopTracer::create();
    }
}
