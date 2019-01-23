<?php

namespace DDTrace\Util;

use DDTrace\GlobalTracer;

class CodeTracer
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
            self::$instance = new CodeTracer();
        }

        return self::$instance;
    }

    /**
     * @param string $className
     * @param string $method
     * @param \Closure|null $preCallHook
     * @param \Closure|null $postCallHook
     */
    public function tracePublicMethod($className, $method, \Closure $preCallHook = null, \Closure $postCallHook = null)
    {
        dd_trace($className, $method, function () use (
            $className,
            $method,
            $preCallHook,
            $postCallHook
        ) {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startActiveSpan($className . '.' . $method);
            $span = $scope->getSpan();
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
