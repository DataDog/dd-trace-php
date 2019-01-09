<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/GlobalTracer.php
 */

namespace DDTrace;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\OpenTracer\Tracer as OpenTracer;

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
     * @param TracerInterface $tracer
     */
    public static function set(TracerInterface $tracer)
    {
        self::$instance = $tracer;
        if (class_exists('\OpenTracing\GlobalTracer')) {
            \OpenTracing\GlobalTracer::set(
                new OpenTracer($tracer)
            );
        }
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
