<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\Tag;
use DDTrace\GlobalTracer;

abstract class Integration
{
    // Possible statuses for the concrete:
    //   - NOT_LOADED   : It has not been loaded, but it may be loaded at a future time if the preconditions match
    //   - LOADED       : It has been loaded, no more work required.
    //   - NOT_AVAILABLE: Prerequisites are not matched and won't be matched in the future.
    const NOT_LOADED = 0;
    const LOADED = 1;
    const NOT_AVAILABLE = 2;

    const CLASS_NAME = '';

    /**
     * @var DefaultIntegrationConfiguration|mixed
     */
    protected $configuration;

    /**
     * @return string The integration name.
     */
    abstract public function getName();

    public function __construct()
    {
        $this->configuration = $this->buildConfiguration();
    }

    /**
     * @return bool
     */
    public function isTraceAnalyticsEnabled()
    {
        return $this->configuration->isTraceAnalyticsEnabled();
    }

    /**
     * @return float
     */
    public function getTraceAnalyticsSampleRate()
    {
        return $this->configuration->getTraceAnalyticsSampleRate();
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
     * Build the integration's configuration object. Override to provide your own implementation.
     *
     * @return DefaultIntegrationConfiguration|mixed
     */
    protected function buildConfiguration()
    {
        return new DefaultIntegrationConfiguration($this->getName(), $this->requiresExplicitTraceAnalyticsEnabling());
    }

    /**
     * @return DefaultIntegrationConfiguration|mixed
     */
    protected function getConfiguration()
    {
        return $this->configuration;
    }

    public static function load()
    {
        // See comment on the commented out abstract function definition.
        static::loadIntegration();
        return self::LOADED;
    }

    /**
     * Each integration's implementation of this method will include
     * all the methods to trace by calling the traceMethod() for each
     * method that should be traced.
     *
     * @return void
     */
    // The abstract method definition is disabled because of PHP throwing the error:
    // ErrorException: Static function DDTrace\Integrations\Integration::loadIntegration() should not be abstract
    // We should refactor this piece of code using interfaces.
    //
    // abstract protected static function loadIntegration();

    /**
     * @param string $method
     * @param \Closure|null $preCallHook
     * @param \Closure|null $postCallHook
     * @param Integration|null $integration
     */
    protected static function traceMethod(
        $method,
        \Closure $preCallHook = null,
        \Closure $postCallHook = null,
        Integration $integration = null
    ) {
        $className = static::CLASS_NAME;
        $integrationClass = get_called_class();
        dd_trace($className, $method, function () use (
            $className,
            $integrationClass,
            $method,
            $preCallHook,
            $postCallHook,
            $integration
        ) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startActiveSpan($className . '.' . $method);
            $span = $scope->getSpan();

            if (null !== $integration) {
                $span->setIntegration($integration);
            }

            $integrationClass::setDefaultTags($span, $method);
            if (null !== $preCallHook) {
                $preCallHook($span, func_get_args());
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
        });
    }

    /**
     * @param Span $span
     * @param string $method
     */
    public static function setDefaultTags(Span $span, $method)
    {
        $span->setTag(Tag::RESOURCE_NAME, $method);
    }

    /**
     * Tells whether or not the provided application should be loaded.
     *
     * @param string $name
     * @return bool
     */
    protected static function shouldLoad($name)
    {
        if (!Configuration::get()->isIntegrationEnabled($name)) {
            return false;
        }
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load integration.', E_USER_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Merge an associative array of span metadata into a span.
     *
     * @param Span $span
     * @param array $meta
     */
    public static function mergeTagsLegacyApi(Span $span, $meta)
    {
        foreach ($meta as $tagName => $value) {
            $span->setTag($tagName, $value);
        }
    }
}
