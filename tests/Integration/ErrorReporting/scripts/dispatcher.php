<?php

namespace MyApp\MyBundle;

class Dispatcher
{
    public function routeThatThrowsException()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        (new SomeServiceForExceptions())->doThrow();
    }

    public function dispatchWithException()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            error_log('Exception as seen by catch: ' . print_r($ex->getTraceAsString(), 1));
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    public function dispatchWithExceptionRethrow()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public function dispatchWithThrowable()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Throwable $ex) {
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    public function dispatchInternalExcetionNotResultingInError()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            // doing nothing
        }
    }
}
