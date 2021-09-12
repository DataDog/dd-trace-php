--TEST--
Span service defaults to DD_SERVICE
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_SERVICE=datadog-php-test
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_DEBUG=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\trace_method(
    'Application',
    'prehook',
    [
        'prehook' => function (DDTrace\SpanData $span) {
            echo 'Service: ', $span->service, "\n";
        },
    ]
);

DDTrace\trace_method(
    'Application',
    'posthook',
    [
        'posthook' => function (DDTrace\SpanData $span) {
            echo 'Service: ', $span->service, "\n";
        },
    ]
);

final class Application
{
    public static function prehook()
    {
        echo __METHOD__, "\n";
    }

    public static function posthook()
    {
        echo __METHOD__, "\n";
    }
}

Application::prehook();
Application::posthook();

?>
--EXPECT--
Service: datadog-php-test
Application::prehook
Application::posthook
Service: datadog-php-test

