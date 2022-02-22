<?php

namespace DDTrace\Integrations;

use DDTrace\Contracts\Span;
use DDTrace\SpanData;
use DDTrace\Tag;

abstract class Integration
{
    // Possible statuses for the concrete:
    //   - NOT_LOADED   : It has not been loaded, but it may be loaded at a future time if the preconditions match
    //   - LOADED       : It has been loaded, no more work required.
    //   - NOT_AVAILABLE: Prerequisites are not matched and won't be matched in the future.
    const NOT_LOADED = 0;
    const LOADED = 1;
    const NOT_AVAILABLE = 2;

    /**
     * @var DefaultIntegrationConfiguration|mixed
     */
    protected $configuration;

    /**
     * Load the integration
     *
     * @return int
     */
    abstract public function init();

    /**
     * @return string The integration name.
     */
    abstract public function getName();

    final public function __construct()
    {
        $this->configuration = new DefaultIntegrationConfiguration(
            $this->getName(),
            $this->requiresExplicitTraceAnalyticsEnabling()
        );
    }

    public function addTraceAnalyticsIfEnabled(SpanData $span)
    {
        if (!$this->configuration->isTraceAnalyticsEnabled()) {
            return;
        }
        $span->metrics[Tag::ANALYTICS_KEY] = $this->configuration->getTraceAnalyticsSampleRate();
    }

    /**
     * Root spans still uses the legacy userland API. This method has to be removed once we move to internal span
     * representation also for the root span.
     *
     * @param Span $span
     * @return void
     */
    public function addTraceAnalyticsIfEnabledLegacy(Span $span)
    {
        if (!$this->configuration->isTraceAnalyticsEnabled()) {
            return;
        }
        $span->setMetric(Tag::ANALYTICS_KEY, $this->configuration->getTraceAnalyticsSampleRate());
    }

    /**
     * Sets common error tags for an exception.
     *
     * @param SpanData $span
     * @param \Exception $exception
     */
    public function setError(SpanData $span, \Exception $exception)
    {
        $span->meta[Tag::ERROR_MSG] = $exception->getMessage();
        $span->meta[Tag::ERROR_TYPE] = get_class($exception);
        $span->meta[Tag::ERROR_STACK] = $exception->getTraceAsString();
    }

    /**
     * Merge an associative array of span metadata into a span.
     *
     * @param SpanData $span
     * @param array $meta
     */
    public function mergeMeta(SpanData $span, $meta)
    {
        foreach ($meta as $tagName => $value) {
            $span->meta[$tagName] = $value;
        }
    }

    /**
     * @return DefaultIntegrationConfiguration|mixed
     */
    protected function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * Whether or not this integration trace analytics configuration is enabled when the global
     * switch is turned on or it requires explicit enabling.
     *
     * @return bool
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return true;
    }

    /**
     * Tells whether or not the provided integration should be loaded.
     *
     * @param string $name
     * @return bool
     */
    public static function shouldLoad($name)
    {
        if (!\extension_loaded('ddtrace')) {
            \trigger_error('ddtrace extension required to load integration.', \E_USER_WARNING);
            return false;
        }

        return \ddtrace_config_integration_enabled($name);
    }

    public static function toString($value)
    {
        if (gettype($value) == "object") {
            if (method_exists($value, "__toString")) {
                try {
                    return (string)$value;
                } catch (\Throwable $t) {
                } catch (\Exception $e) {
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
}

function load_deferred_integration($integrationName)
{
    // it should have already been loaded (in current architecture)
    if (
        \class_exists($integrationName, $autoload = false)
        && \is_subclass_of($integrationName, 'DDTrace\\Integrations\\Integration')
    ) {
        /** @var Integration $integration */
        $integration = new $integrationName();
        $integration->init();
    }
}
