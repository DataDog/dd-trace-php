<?php

namespace DDTrace\Tests\Integrations\Logs;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class BaseLogsTest extends \DDTrace\Tests\Common\IntegrationTestCase
{
    protected static function logFile()
    {
        return "/tmp/test-" . substr(static::class, strrpos(static::class, '\\') + 1) . '.log';
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();
        shell_exec('rm -f ' . static::logFile());
    }

    protected function getTestFileContents(): string
    {
        $filename = static::logFile();
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        return $contents;
    }

    protected function withPlaceholders(
        string $levelNameFn,
        $logger,
        string $expectedRegex,
        bool $is128bit = false,
        $logLevelName = null
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=logs_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0'),
            'DD_LOGS_INJECTION=1'
        ]);

        $this->isolateTracer(function () use ($levelNameFn, $logger, $is128bit, $logLevelName) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            if ($logLevelName) {
                $logger->{$levelNameFn}($logLevelName, "A $logLevelName message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env%]");
            } else {
                $logger->{$levelNameFn}("A $levelNameFn message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env%]");
            }

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }

    protected function inContext(
        string $levelNameFn,
        $logger,
        string $expectedRegex,
        bool $is128bit = false,
        $logLevelName = null
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=logs_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0'),
            'DD_LOGS_INJECTION=1'
        ]);

        $this->isolateTracer(function () use ($levelNameFn, $logger, $is128bit, $logLevelName) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            if ($logLevelName) {
                $logger->{$levelNameFn}($logLevelName, "A $logLevelName message");
            } else {
                $logger->{$levelNameFn}("A $levelNameFn message");
            }

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }

    protected function appended(
        string $levelNameFn,
        $logger,
        string $expectedRegex,
        bool $is128bit = false,
        $logLevelName = null
    ) {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=1',
            'APP_NAME=logs_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0'),
            'DD_LOGS_INJECTION=1'
        ]);

        $this->isolateTracer(function () use ($levelNameFn, $logger, $is128bit, $logLevelName) {
            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            if ($logLevelName) {
                $logger->{$levelNameFn}($logLevelName, "A $logLevelName message");
            } else {
                $logger->{$levelNameFn}("A $levelNameFn message");
            }

            close_span();
        });

        $this->assertRegularExpression($expectedRegex, $this->getTestFileContents());
    }

    public function usingJson(
        string $levelNameFn,
        $jsonFormattedLogger,
        string $expectedRegex,
        bool $is128bit = false,
        $logLevelName = null
    ) { // Just for a matter of readability of the tests
        $this->inContext(
            $levelNameFn,
            $jsonFormattedLogger,
            $expectedRegex,
            $is128bit,
            $logLevelName
        );
    }
}
