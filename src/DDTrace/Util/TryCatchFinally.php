<?php

namespace DDTrace\Util;

use DDTrace\Contracts\Span;
use DDTrace\Scope;

/**
 * PHP 5.4 compatible methods to workaround the missing try-catch-finally block.
 */
class TryCatchFinally
{
    /**
     * PHP 5.4 compatible try-catch-finally to execute instance methods.
     *
     * @param Scope $scope
     * @param mixed $instance
     * @param string $method
     * @param array $args
     * @param \Closure $afterResult
     * @return mixed|null
     * @throws \Exception
     */
    public static function executePublicMethod(Scope $scope, $instance, $method, array $args = [], $afterResult = null)
    {
        $thrown = null;
        $result = null;
        /** @var Span $span */
        $span = $scope->getSpan();
        try {
            $result = call_user_func_array([$instance, $method], $args);
            if ($afterResult) {
                $afterResult($result, $span, $scope);
            }
        } catch (\Exception $ex) {
            $thrown = $ex;
            $span->setError($ex);
        }

        $scope->close();
        if ($thrown) {
            throw $thrown;
        }

        return $result;
    }

    /**
     * PHP 5.4 compatible try-catch-finally to execute instance methods.
     *
     * @param Scope $scope
     * @param mixed $instance
     * @param string $method
     * @param array $args
     * @param \Closure $afterResult
     * @return mixed|null
     * @throws \Exception
     */
    public static function executeAnyMethod(Scope $scope, $instance, $method, array $args = [], $afterResult = null)
    {
        $thrown = null;
        $result = null;
        /** @var Span $span */
        $span = $scope->getSpan();

        try {
            $result = call_user_func(\Closure::bind(function () use ($instance, $method, $args) {
                return call_user_func_array([$instance, $method], $args);
            }, null, $instance));

            if ($afterResult) {
                $afterResult($result, $span, $scope);
            }
        } catch (\Exception $ex) {
            $thrown = $ex;
            $span->setError($ex);
        }

        $scope->close();
        if ($thrown) {
            throw $thrown;
        }

        return $result;
    }

    /**
     * PHP 5.4 compatible try-catch-finally to execute functions.
     *
     * @param Scope $scope
     * @param string $function
     * @param array $args
     * @param \Closure $afterResult
     * @return mixed|null
     * @throws \Exception
     */
    public static function executeFunction(Scope $scope, $function, array $args = [], $afterResult = null)
    {
        $thrown = null;
        $result = null;
        /** @var Span $span */
        $span = $scope->getSpan();
        try {
            $result = call_user_func_array($function, $args);
            if ($afterResult) {
                $afterResult($result, $span, $scope);
            }
        } catch (\Exception $ex) {
            $thrown = $ex;
            $span->setError($ex);
        }

        $scope->close();
        if ($thrown) {
            throw $thrown;
        }

        return $result;
    }
}
