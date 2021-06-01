<?php

class Handler
{
    public static function handleExceptionRenderViaHeaderFunction($ex)
    {
        // Examples:
        //   - Cake 2.8
        //       - https://github.com/cakephp/cakephp/blob/cf14e6546ec44e3369e3531add11fdb946656280/lib/Cake/Network/CakeResponse.php#L430
        header('HTTP/1.1 500 Internal Server Obfuscated Error');
    }

    public static function handleExceptionConvertToUserError($ex)
    {
        // Case when an exception is thrown while rendering the original exception (so we cannot use the same rendering
        // mechanism)
        // Examples:
        //  - Cake 2.8
        //      - https://github.com/cakephp/cakephp/blob/cf14e6546ec44e3369e3531add11fdb946656280/lib/Cake/Error/ErrorHandler.php#L129-L138
        $exceptionWhileRenderigRealException = new Exception("Exception thrown while handling the original exception");
        set_error_handler('handle_error_generated_while_rendering');

        $message = sprintf(
            "[%s] %s\n%s", // Keeping same message format
            get_class($exceptionWhileRenderigRealException),
            $exceptionWhileRenderigRealException->getMessage(),
            $exceptionWhileRenderigRealException->getTraceAsString()
        );

        trigger_error($message, E_USER_ERROR);
    }

    public static function handleError()
    {
        header('HTTP/1.1 500 Internal Server Obfuscated Error');
    }

    public static function handleErrorNotResultingInError()
    {
        // doing nothing
    }

    public static function notHandlingError()
    {
        return false;
    }
}

function throw_application_error()
{
    throw new Exception("Application error");
}

function throw_handling_error()
{
    throw new Exception("Inner handling error");
}

// Cake 2.8
function handle_error_generated_while_rendering()
{
    try {
        throw_application_error();
    } catch (Exception $ex) {
        try {
            try {
                throw new \Exception("irrelevant");
            } catch (Exception $ex) {
                // normally handled, has no impact
            }

            throw_handling_error();
        } catch (Exception $ex) {
            (new Handler())->handleExceptionRenderViaHeaderFunction($ex);
        }
    }
}
