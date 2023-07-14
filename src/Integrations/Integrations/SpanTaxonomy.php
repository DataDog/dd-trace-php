<?php

namespace DDTrace\Integrations;

use DDTrace\SpanData;

final class SpanTaxonomy
{
    /**
     * @param SpanData $span
     * @param string $fallbackName
     */
    public static function handleInternalSpanServiceName(SpanData $span, $fallbackName)
    {
        $flatServiceNames =
            \PHP_MAJOR_VERSION > 5
                && \dd_trace_env_config('DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED');

        if ($flatServiceNames) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan) {
                $span->service = $rootSpan->service;
            } else {
                $span->service = \ddtrace_config_app_name($fallbackName);
            }
        } else {
            $span->service = $fallbackName;
        }
    }

    public static function registerCurrentRootService($serviceName)
    {
        self::$currentRootService = $serviceName;
    }
}
