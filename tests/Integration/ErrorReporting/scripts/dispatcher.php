<?php

namespace MyApp\MyBundle;

class Dispatcher
{
    public function dispatchWithException()
    {
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    public function dispatchWithExceptionRethrow()
    {
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public function dispatchWithThrowable()
    {
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Throwable $ex) {
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    public function dispatchInternalExcetionNotResultingInError()
    {
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            // doing nothing
        }
    }
}

class SomeServiceForExceptions
{
    public function doThrow()
    {
        throw new \Exception("Exception thrown by inner service");
    }
}
