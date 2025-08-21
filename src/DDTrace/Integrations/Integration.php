<?php

namespace DDTrace\Integrations;

use DDTrace\RootSpanData;
use DDTrace\SpanData;
use DDTrace\Tag;

abstract class Integration implements \DDTrace\Integration
{
    /**
     * @return string The integration name.
     */
    public static function getName(): string
    {
        return static::NAME;
    }

    public static function addTraceAnalyticsIfEnabled(SpanData $span)
    {
        $name = static::NAME;
        if (\DDTrace\Config\integration_analytics_enabled($name)
            || (!static::requiresExplicitTraceAnalyticsEnabling() && \dd_trace_env_config("DD_TRACE_ANALYTICS_ENABLED"))) {
            $span->metrics[Tag::ANALYTICS_KEY] = \DDTrace\Config\integration_analytics_sample_rate($name);
        }
    }

    /**
     * Whether this integration trace analytics configuration is not enabled when DD_TRACE_ANALYTICS_ENABLED=1 is specified.
     *
     * Trace Analytics are generally enabled by default for top-level integrations, i.e. frameworks and webservers.
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return true;
    }

    /**
     * Tells whether the provided integration should be loaded.
     */
    public static function shouldLoad(string $name): bool
    {
        if (!\extension_loaded('ddtrace')) {
            \trigger_error('ddtrace extension required to load integration.', \E_USER_WARNING);
            return false;
        }

        return \ddtrace_config_integration_enabled($name);
    }

    public static function toString($value): string
    {
        if (gettype($value) == "object") {
            if (method_exists($value, "__toString")) {
                try {
                    return (string)$value;
                } catch (\Throwable $t) {
                }
            }
            if (PHP_VERSION_ID >= 70200) {
                $object_id = spl_object_id($value);
            } else {
                static $object_base_hash;
                if ($object_base_hash === null) {
                    ob_start();
                    $class = new \stdClass();
                    $hash = spl_object_hash($class);
                    var_dump($class);
                    preg_match('(#\K\d+)', ob_get_clean(), $m);
                    $object_base_hash = hexdec(substr($hash, 0, 16)) ^ $m[0];
                }
                $object_id = $object_base_hash ^ hexdec(substr(spl_object_hash($value), 0, 16));
            }
            return "object(" . get_class($value) . ")#$object_id";
        }
        return (string) $value;
    }

    public static function handleInternalSpanServiceName(SpanData $span, string $fallbackName, bool $skipFlattening = false)
    {
        $flatServiceNames =
            !$skipFlattening && \dd_trace_env_config('DD_TRACE_REMOVE_INTEGRATION_SERVICE_NAMES_ENABLED');

        if ($flatServiceNames) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan) {
                $service = $rootSpan->service;
            } else {
                $service = \ddtrace_config_app_name($fallbackName);
            }
        } else {
            $service = $fallbackName;
        }

        $mapping = \dd_trace_env_config('DD_SERVICE_MAPPING');
        if (isset($mapping[$service])) {
            $service = $mapping[$service];
        }
        $span->service = $service;
    }

    public static function handleOrphan(SpanData $span)
    {
        if (
            \dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
            && $span instanceof RootSpanData
            && empty($span->parentId)
        ) {
            $prioritySampling = \DDTrace\get_priority_sampling();
            if (
                $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP
                || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_USER_KEEP
                || $prioritySampling == DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT
            ) {
                \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
            }
        }
    }
}
