<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Span;
use OpenTracing\GlobalTracer;

abstract class Integration
{
    // Possible statuses for the concrete
    const NOT_LOADED = 0;
    const LOADED = 1;
    const NOT_AVAILABLE = 2;

    const CLASS_NAME = '';

    public static function load()
    {
        if (!class_exists(static::CLASS_NAME)) {
            return Integration::NOT_LOADED;
        }
        // See comment on the commented out abstract function definition.
        static::loadIntegration();

        return Integration::LOADED;
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
     */
    protected static function traceMethod($method, \Closure $preCallHook = null, \Closure $postCallHook = null)
    {
        $className = static::CLASS_NAME;
        $integrationClass = get_called_class();
        dd_trace($className, $method, function () use (
            $className,
            $integrationClass,
            $method,
            $preCallHook,
            $postCallHook
        ) {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startActiveSpan($className . '.' . $method);
            $span = $scope->getSpan();
            $integrationClass::setDefaultTags($span, $method);
            if (null !== $preCallHook) {
                $preCallHook($span, $args);
            }

            $returnVal = null;
            $thrownException = null;
            try {
                $returnVal = call_user_func_array([$this, $method], $args);
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
        $span->setTag(Tags\RESOURCE_NAME, $method);
    }
}
