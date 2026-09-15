<?php

namespace DDTrace;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/GlobalTracer.php
 */

use DDTrace\Contracts\Tracer as TracerInterface;

/*
 * @deprecated This class is deprecated, you should use the Otel or the extension API instead.
 */
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

        // Ensure that, when trying to use the legacy API, our Tracer is also loaded
        if (\extension_loaded('ddtrace') && class_exists(Tracer::class)) {
            /** @phpstan-ignore-next-line */
            return self::$instance = new Tracer();
        }

        return self::$instance = NoopTracer::create();
    }
}
