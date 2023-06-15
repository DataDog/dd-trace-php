<?php

namespace DDTrace\Tests\Integrations\Logs;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class BaseLogsTest extends \DDTrace\Tests\Common\IntegrationTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        shell_exec('rm -f /tmp/test.log');
    }

    protected function getTestFileContents(): string
    {
        $filename = '/tmp/test.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        return $contents;
    }

    protected function withPlaceholders(
        string $levelName,
        $logger,
        string $expectedRegex,
        bool $is128bit = false
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $this->isolateTracer(function () use ($levelName, $logger, $is128bit) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env% %level_name%]");

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }

    protected function inContext(
        string $levelName,
        $logger,
        string $expectedRegex,
        bool $is128bit = false
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $this->isolateTracer(function () use ($levelName, $logger, $is128bit) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }

    protected function appended(
        string $levelName,
        $logger,
        string $expectedRegex,
        bool $is128bit = false
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=1',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $this->isolateTracer(function () use ($levelName, $logger, $is128bit) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }
}
