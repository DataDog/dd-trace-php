<?php

namespace Luca;

use DDTrace\SpanData;

error_log("Printed to the error log");
echo "This is the playground!\n";


class SomeService
{
    public function tracedOk()
    {
        \usleep(2000);
    }

    public function tracedWithException()
    {
        $this->exception();
    }

    public function tracedWithFatal()
    {
        $this->fatal();
    }

    public function tracedWithTriggeredError()
    {
        $this->trigger_error();
    }

    public function exception()
    {
        \usleep(2000);
        throw new \Exception("this is a fake exception generated for fun!");
    }

    public function fatal()
    {
        \usleep(2000);
        new NotExisting();
    }

    public function trigger_error()
    {
        \usleep(2000);
        trigger_error("Fatal error", E_USER_ERROR);
    }
}

\DDTrace\trace_method('Luca\SomeService', 'tracedOk', function (SpanData $span) {
    $span->service = \ddtrace_config_app_name();
});
\DDTrace\trace_method('Luca\SomeService', 'tracedWithException', function (SpanData $span) {
    $span->service = \ddtrace_config_app_name();
});
\DDTrace\trace_method('Luca\SomeService', 'tracedWithFatal', function (SpanData $span) {
    $span->service = \ddtrace_config_app_name();
});
\DDTrace\trace_method('Luca\SomeService', 'tracedWithTriggeredError', function (SpanData $span) {
    $span->service = \ddtrace_config_app_name();
});

$service = new SomeService();
$service->tracedOk();
echo "Everything run successfully\n";
