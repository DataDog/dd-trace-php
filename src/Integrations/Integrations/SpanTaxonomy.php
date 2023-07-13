<?php

namespace DDTrace\Integrations;

use DDTrace\SpanData;

final class SpanTaxonomy
{
    private static $instance = null;

    /** @var string|null */
    private static $currentRootService = null;

    private function __construct()
    {
    }

    /**
     * @return SpanTaxonomy
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new SpanTaxonomy();
        }

        return self::$instance;
    }

    /**
     * @param SpanData $span
     * @param string $fallbackName
     */
    public function handleInternalSpanServiceName(SpanData $span, $fallbackName = null)
    {
        $flatServiceNames =
            \PHP_MAJOR_VERSION > 5
                && \dd_trace_env_config('DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED');

        if ($flatServiceNames) {
            $span->service = empty(self::$currentRootService)
                ? \ddtrace_config_app_name($fallbackName)
                : self::$currentRootService;
        } else {
            $span->service = empty($fallbackName) ? \ddtrace_config_app_name() : $fallbackName;
        }
    }

    public static function registerCurrentRootService($serviceName)
    {
        self::$currentRootService = $serviceName;
    }
}
