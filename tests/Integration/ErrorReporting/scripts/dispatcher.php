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
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    private function rethrowWithPrevious(\Exception $ex)
    {
        throw new \ErrorException("Rethrown Exception", 0, 1, "random file", 2, $ex);
    }

    public function dispatchWithPreviousException()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            $this->rethrowWithPrevious($ex);
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

    public function dispatchWithMultipleCatches()
    {
        try {
            require_once __DIR__ . '/service_throwing_exception.php';
            (new SomeServiceForExceptions())->doThrow();
        } catch (\DomainException $ex) {
        } catch (\RuntimeException $ex) {
        } catch (\Throwable $ex) {
            header('HTTP/1.1 500 Internal Server Obfuscated Error');
        }
    }

    public function nestedDispatchWithException()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            $this->emit500();
        }
    }

    private function emit500()
    {
        header('HTTP/1.1 500 Internal Server Obfuscated Error');
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

    /**
     * As seen in drupal 8, exceptions can be used to create a RedirectResponse that are rendered as 303.
     *   - https://github.com/drupal/drupal/blob/802b06b100a33a79ab254104252464dcf68c05d0/core/lib/Drupal/Core/Form/FormSubmitter.php#L122-L145
     */
    public function dispatchInternalExcetionUsedForRedirect()
    {
        require_once __DIR__ . '/service_throwing_exception.php';
        try {
            (new SomeServiceForExceptions())->doThrow();
        } catch (\Exception $ex) {
            header('HTTP/1.1 303 See Other');
        }
    }
}
