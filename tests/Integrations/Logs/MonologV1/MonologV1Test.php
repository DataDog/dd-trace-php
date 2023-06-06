<?php

namespace DDTrace\Tests\Integrations\Logs\MonologV1;

use DDTrace\Tests\Common\IntegrationTestCase;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class MonologV1Test extends IntegrationTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        shell_exec('rm -f /tmp/monolog1.log');
    }
    protected function withPlaceholders(string $levelName, bool $is128bit = false)
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $traces = $this->isolateTracer(function () use ($levelName, $is128bit) {
            $logger = new Logger('test');
            // Log to a temporary file
            $logger->pushHandler(new StreamHandler('/tmp/monolog1.log'));

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env% %level_name%]");

            close_span();
        });

        //fwrite(STDERR, json_encode($traces, JSON_PRETTY_PRINT) . PHP_EOL);

        $filename = '/tmp/monolog1.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        #fwrite(STDERR, $contents . PHP_EOL);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message \[dd.trace_id=\"192f3581c8461c79abf2684ee31ce27d\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\] \[\] \[\]/",
                $contents
            );
        } else {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message \[dd.trace_id=\"\d+\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\] \[\] \[\]/",
                $contents
            );
        }
    }

    protected function inContext64Bit(string $levelName, bool $is128bit = false)
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $traces = $this->isolateTracer(function () use ($levelName, $is128bit) {
            $logger = new Logger('test');
            // Log to a temporary file
            $logger->pushHandler(new StreamHandler('/tmp/monolog1.log'));

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        //fwrite(STDERR, json_encode($traces, JSON_PRETTY_PRINT) . PHP_EOL);

        $filename = '/tmp/monolog1.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        #fwrite(STDERR, $contents . PHP_EOL);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message {\"dd\":{\"trace_id\":\"192f3581c8461c79abf2684ee31ce27d\",\"span_id\":\"\d+\",\"service\":\"my-service\",\"version\":\"4.2\",\"env\":\"my-env\"},\"level_name\":\"$levelName\"} \[\]$/",
                $contents
            );
        } else {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message {\"dd\":{\"trace_id\":\"\d+\",\"span_id\":\"\d+\",\"service\":\"my-service\",\"version\":\"4.2\",\"env\":\"my-env\"},\"level_name\":\"$levelName\"} \[\]$/",
                $contents
            );
        }
    }

    protected function appended64bit(string $levelName, bool $is128bit = false)
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=1',
            'APP_NAME=monolog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $traces = $this->isolateTracer(function () use ($levelName, $is128bit) {
            $logger = new Logger('test');
            // Log to a temporary file
            $logger->pushHandler(new StreamHandler('/tmp/monolog1.log'));

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        //fwrite(STDERR, json_encode($traces, JSON_PRETTY_PRINT) . PHP_EOL);

        $filename = '/tmp/monolog1.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        #fwrite(STDERR, $contents . PHP_EOL);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message \[dd.trace_id=\"192f3581c8461c79abf2684ee31ce27d\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\] \[\] \[\]/",
                $contents
            );
        } else {
            $this->assertMatchesRegularExpression(
                "/^\[.*\] test.$levelNameUpper: A $levelName message \[dd.trace_id=\"\d+\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\] \[\] \[\]/",
                $contents
            );
        }
    }

    public function testDebugWithPlaceholders64bit()
    {
        $this->withPlaceholders('debug');
    }

    public function testDebugInContext64bit()
    {
        $this->inContext64Bit('debug');
    }

    public function testDebugAppended64bit()
    {
        $this->appended64bit('debug');
    }

    public function testDebugWithPlaceholders128bit()
    {
        $this->withPlaceholders('debug', true);
    }

    public function testDebugInContext128bit()
    {
        $this->inContext64Bit('debug', true);
    }

    public function testDebugAppended128bit()
    {
        $this->appended64bit('debug', true);
    }

    // TODO: Error tracking
}
