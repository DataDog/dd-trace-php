<?php

namespace DDTrace;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/GlobalTracer.php
 */

use DDTrace\Contracts\Tracer as TracerInterface;

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
        if (\extension_loaded('ddtrace') && function_exists('ddtrace_legacy_tracer_autoloading_possible')) {
            /** @phpstan-ignore-next-line */
            Bootstrap::tracerOnce();
            if (null !== self::$instance) {
                return self::$instance;
            }
        }

        return self::$instance = NoopTracer::create();
    }
}
