<?php

switch ($_SERVER['REQUEST_URI']) {
    case "/unhandled-user-error-index":
        trigger_error("Index message", E_USER_ERROR);
        break;

    case "/unhandled-exception-index":
        throw new Exception("Exception in index");
        break;

    case "/unhandled-user-error-external":
        require __DIR__ . '/trigger_user_error.php';
        break;

    case "/unhandled-exception-external":
        require __DIR__ . '/trigger_exception.php';
        break;

    case "/unhandled-user-error-in-function":
        require __DIR__ . '/functions.php';
        do_trigger_error();
        break;

    case "/unhandled-exception-in-function":
        require __DIR__ . '/functions.php';
        do_throw_exception();
        break;

    case "/unhandled-user-error-in-nested-function":
        require __DIR__ . '/functions.php';
        function_that_calls_a_function_that_triggers_an_error();
        break;

    case "/unhandled-exception-in-nested-function":
        require __DIR__ . '/functions.php';
        function_that_calls_a_function_that_throws_an_exception();
        break;

    case "/unhandled-exception-class":
        require __DIR__ . '/dispatcher.php';
        (new MyApp\MyBundle\Dispatcher())->dispatchWithException();
        break;

    case "/unhandled-exception-rethrown-class":
        require __DIR__ . '/dispatcher.php';
        (new MyApp\MyBundle\Dispatcher())->dispatchWithExceptionRethrow();
        break;

    case "/unhandled-throwable-class":
        require __DIR__ . '/dispatcher.php';
        (new MyApp\MyBundle\Dispatcher())->dispatchWithThrowable();
        break;

    case "/handled-user-error-header":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/functions.php';
        set_error_handler('Handler::handleError');
        function_that_calls_a_function_that_triggers_an_error();
        break;

    case "/handled-exception-class-header":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/dispatcher.php';
        set_exception_handler('Handler::handleExceptionRenderViaHeaderFunction');
        (new MyApp\MyBundle\Dispatcher())->dispatchWithException();
        break;

    case "/handled-exception-function-header":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/functions.php';
        set_exception_handler('Handler::handleExceptionRenderViaHeaderFunction');
        function_that_calls_a_function_that_throws_an_exception();
        break;

    case "/handled-exception-rethrow-class-header":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/dispatcher.php';
        set_exception_handler('Handler::handleExceptionRenderViaHeaderFunction');
        (new MyApp\MyBundle\Dispatcher())->dispatchWithExceptionRethrow();
        break;

    case "/handled-throwable-class-header":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/dispatcher.php';
        set_exception_handler('Handler::handleExceptionRenderViaHeaderFunction');
        (new MyApp\MyBundle\Dispatcher())->dispatchWithThrowable();
        break;

    case "/handled-exception-class-converted-user-error":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/dispatcher.php';
        set_exception_handler('Handler::handleExceptionConvertToUserError');
        (new MyApp\MyBundle\Dispatcher())->dispatchWithException();
        break;

    case "/handled-throwable-class-converted-user-error":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/dispatcher.php';
        set_exception_handler('Handler::handleExceptionConvertToUserError');
        (new MyApp\MyBundle\Dispatcher())->dispatchWithThrowable();
        break;

    case "/handled-exception-function-converted-user-error":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/functions.php';
        set_exception_handler('Handler::handleExceptionConvertToUserError');
        function_that_calls_a_function_that_throws_an_exception();
        break;

    case "/handled-exception-generated-while-rendering-exception":
        require __DIR__ . '/handlers.php';
        handle_error_generated_while_rendering();
        break;

    case "/internal-exception-not-resulting-in-error":
        require __DIR__ . '/dispatcher.php';
        (new MyApp\MyBundle\Dispatcher())->dispatchInternalExcetionNotResultingInError();
        break;

    case "/internal-user-error-not-resulting-in-error":
        require __DIR__ . '/handlers.php';
        require __DIR__ . '/functions.php';
        set_error_handler('Handler::handleErrorNotResultingInError');
        function_that_calls_a_function_that_triggers_an_error();
        break;
}
