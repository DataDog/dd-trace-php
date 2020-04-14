<?php
putenv("DD_TRACE_ENABLED=true");
putenv("DD_TRACE_CLI_ENABLED=true");

require_once __DIR__ . '/dd_required_deps_autoloader.php';
require_once __DIR__ . '/dd_optional_deps_autoloader.php';

spl_autoload_register(['\DDTrace\Bridge\OptionalDepsAutoloader', 'load'], true, true);
spl_autoload_register(['\DDTrace\Bridge\RequiredDepsAutoloader', 'load'], true, true);

const DD_TRACE_VERSION = "123";

function dd_trace_internal_fn()
{
    // echo "dd_trace_internal_fn" . PHP_EOL;
};

function dd_trace_push_span_id()
{
    // echo "dd_trace_push_span_id" . PHP_EOL;
};

function dd_trace($a, $b)
{
    // echo "dd_trace " . implode(", ", [$a]) . PHP_EOL;
}

function dd_trace_method($clazz, $method)
{
    $key = array_search(__FUNCTION__, array_column(debug_backtrace(), 'function'));
    // var_dump(debug_backtrace()[$key]['file']);
    $callerFile = explode("/src/", debug_backtrace()[$key]['file'])[1];

    echo "\"" . $clazz . ":" . $method . "\": \"" . $callerFile . "\", " . PHP_EOL;
}

function dd_trace_function()
{
    // echo "dd_trace_function" . PHP_EOL;
}

function dd_trace_disable_in_request()
{
    // echo "dd_trace_disable_in_request" . PHP_EOL;
    return False;
}

function dd_trace_env_config()
{
    // echo "dd_trace_env_config" . PHP_EOL;
    return True;
}

echo "[" . PHP_EOL;
require_once __DIR__ . "/dd_init.php";
echo "]" . PHP_EOL;
