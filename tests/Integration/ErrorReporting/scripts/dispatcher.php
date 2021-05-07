<?php

namespace MyApp\MyBundle;

class Dispatcher
{
    public function dispatchWithException()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
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
        require_once __DIR__ . '/service_throwing_throwable.php';
        try {
            (new SomeServiceForThrowables())->doThrow();
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
