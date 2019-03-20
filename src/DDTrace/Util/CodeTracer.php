<?php

namespace DDTrace\Util;

use DDTrace\Contracts\Integration;
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
     */
    public function tracePublicMethod(
        $className,
        $method,
        \Closure $preCallHook = null,
        \Closure $postCallHook = null,
        Integration $integration = null
    ) {
        dd_trace($className, $method, function () use (
            $className,
            $method,
            $preCallHook,
            $postCallHook,
            $integration
        ) {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startActiveSpan($className . '.' . $method);
            $span = $scope->getSpan();

            if ($integration) {
                $span->setIntegration($integration);
            }

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
}
