<?php

namespace DDTrace\Util;

use DDTrace\Integrations\Integration;
use DDTrace\GlobalTracer;

final class CodeTracer
{
    /**
     * @var CodeTracer
     */
    private static $instance;

    /**
     * @return CodeTracer
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $className
     * @param string $method
     * @param \Closure|null $preCallHook
     * @param \Closure|null $postCallHook
     * @param Integration|null $integration
     * @param bool $isTraceAnalyticsCandidate
     */
    public function tracePublicMethod(
        $className,
        $method,
        \Closure $limitedTracerCallHook = null,
        \Closure $preCallHook = null,
        \Closure $postCallHook = null,
        Integration $integration = null,
        $isTraceAnalyticsCandidate = false
    ) {
        dd_trace($className, $method, [
            'instrument_when_limited' => $limitedTracerCallHook ? 1 : 0,
            'innerhook' => function () use (
                $className,
                $method,
                $limitedTracerCallHook,
                $preCallHook,
                $postCallHook,
                $integration,
                $isTraceAnalyticsCandidate
            ) {
                $tracer = GlobalTracer::get();
                if ($tracer->limited()) {
                    if ($limitedTracerCallHook) {
                        $limitedTracerCallHook(func_get_args());
                    }

                    return dd_trace_forward_call();
                }

                $args = func_get_args();
                $scope = $tracer->startActiveSpan($className . '.' . $method);

                $span = $scope->getSpan();

                if ($integration && $isTraceAnalyticsCandidate) {
                    $integration->addTraceAnalyticsIfEnabledLegacy($span);
                }

                if (null !== $preCallHook) {
                    $preCallHook($span, $args);
                }

                $returnVal = null;
                $thrownException = null;
                try {
                    $returnVal = dd_trace_forward_call();
                } catch (\Exception $e) {
                    $span->setError($e);
                    $thrownException = $e;
                }
                if (null !== $postCallHook) {
                    $postCallHook($span, $returnVal);
                }
                $scope->close();
                if (null !== $thrownException) {
                    throw $thrownException;
                }
                return $returnVal;
            }
        ]);
    }
}
