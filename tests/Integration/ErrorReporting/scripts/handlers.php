<?php

class Handler
{
    public function handleExceptionRenderViaHeaderFunction($ex)
    {
        // Examples:
        //   - Cake 2.8
        //       - https://github.com/cakephp/cakephp/blob/cf14e6546ec44e3369e3531add11fdb946656280/lib/Cake/Network/CakeResponse.php#L430
        header('HTTP/1.1 500 Internal Server Obfuscated Error');
    }

    public function handleExceptionConvertToUserError($ex)
    {
        // Case when an exception is thrown while rendering the original exception (so we cannot use the same rendering
        // mechanism)
        // Examples:
        //  - Cake 2.8
        //      - https://github.com/cakephp/cakephp/blob/cf14e6546ec44e3369e3531add11fdb946656280/lib/Cake/Error/ErrorHandler.php#L129-L138
        $exceptionWhileRenderigRealException = new Exception("Exception thrown while rendering the real exception");
        set_error_handler('handle_error_generated_while_rendering');

        $message = sprintf(
            "[%s] %s\n%s", // Keeping same message format
            get_class($exceptionWhileRenderigRealException),
            $exceptionWhileRenderigRealException->getMessage(),
            $exceptionWhileRenderigRealException->getTraceAsString()
        );

        trigger_error($message, E_USER_ERROR);
    }

    public function handleError()
    {
        header('HTTP/1.1 500 Internal Server Obfuscated Error');
    }

    public function handleErrorNotResultingInError()
    {
        // doing nothing
    }
}

// Cake 2.8
function handle_error_generated_while_rendering()
{
    $internal = new Exception("Internal error converted to exception");
    (new Handler())->handleExceptionRenderViaHeaderFunction($internal);
}
