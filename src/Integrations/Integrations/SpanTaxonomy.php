<?php

namespace DDTrace\Integrations;

use DDTrace\SpanData;

final class SpanTaxonomy
{
    private static $instance = null;

    /** @var bool */
    private $flatServiceNames = false;

    /**
     * @param bool $flatServiceNames
     * @return void
     */
    private function __construct($flatServiceNames)
    {
        $this->flatServiceNames = $flatServiceNames;
    }

    /**
     * @return SpanTaxonomy
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance =
                new SpanTaxonomy(
                    \PHP_MAJOR_VERSION > 5
                        && \dd_trace_env_config('DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED')
                );
        }

        return self::$instance;
    }

    /**
     * @param SpanData $span
     * @param string $fallbackName
     */
    public function handleServiceName(SpanData $span, $fallbackName)
    {
        $span->service = ($this->flatServiceNames || empty($fallbackName))
            ? \ddtrace_config_app_name($fallbackName)
            : $fallbackName;
    }
}
