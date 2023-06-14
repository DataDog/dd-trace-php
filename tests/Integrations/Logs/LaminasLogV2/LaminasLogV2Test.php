<?php

namespace DDTrace\Tests\Integrations\Logs\LaminasLogV2;

use DDTrace\Tests\Common\IntegrationTestCase;
use Laminas\Log\Logger;
use Laminas\Log\Writer\Stream;

use function DDTrace\close_span;
use function DDTrace\set_distributed_tracing_context;
use function DDTrace\start_span;

class LaminasLogV2Test extends IntegrationTestCase
{
    protected function ddSetUp()
    {
        parent::ddSetUp();
        shell_exec("rm -f /tmp/laminaslog.log");
    }

    protected function withPlaceholders(string $levelName, bool $is128bit = false)
    {
        $this->putEnvAndReloadConfig([
            'DD_TRACE_APPEND_TRACE_IDS_TO_LOGS=0',
            'APP_NAME=laminaslog_test',
            'DD_ENV=my-env',
            'DD_SERVICE=my-service',
            'DD_VERSION=4.2',
            'DD_TRACE_128_BIT_TRACEID_LOGGING_ENABLED=' . ($is128bit ? '1' : '0')
        ]);

        $this->isolateTracer(function () use ($levelName, $is128bit) {
            $logger = new Logger();
            $writer = new Stream('/tmp/laminaslog.log');
            $logger->addWriter($writer);

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message [%dd.trace_id% %dd.span_id% %dd.service% %dd.version% %dd.env%]");

            close_span();
        });

        $filename = '/tmp/laminaslog.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message \[dd.trace_id=\"192f3581c8461c79abf2684ee31ce27d\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\"\]/",
                $contents
            );
        } else {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message \[dd.trace_id=\"\d+\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\"\]/",
                $contents
            );
        }
    }

    protected function inContext(string $levelName, bool $is128bit = false)
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
            $logger = new Logger();
            $writer = new Stream('/tmp/laminaslog.log');
            $logger->addWriter($writer);

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        $filename = '/tmp/laminaslog.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message {\"dd.trace_id\":\"192f3581c8461c79abf2684ee31ce27d\",\"dd.span_id\":\"\d+\",\"dd.service\":\"my-service\",\"dd.version\":\"4.2\",\"dd.env\":\"my-env\",\"level_name\":\"$levelName\"}/",
                $contents
            );
        } else {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message {\"dd.trace_id\":\"\d+\",\"dd.span_id\":\"\d+\",\"dd.service\":\"my-service\",\"dd.version\":\"4.2\",\"dd.env\":\"my-env\",\"level_name\":\"$levelName\"}/",
                $contents
            );
        }
    }

    protected function appended(string $levelName, bool $is128bit = false)
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
            $logger = new Logger();
            $writer = new Stream('/tmp/laminaslog.log');
            $logger->addWriter($writer);

            $span = start_span();

            if ($is128bit) {
                set_distributed_tracing_context("33475823097097752842117799874953798269", "42");
            }

            $logger->{$levelName}("A $levelName message");

            close_span();
        });

        $filename = '/tmp/laminaslog.log';
        $handle = fopen($filename, 'r');
        $contents = fread($handle, filesize($filename));
        fclose($handle);

        $levelNameUpper = strtoupper($levelName);

        if ($is128bit) {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message \[dd.trace_id=\"192f3581c8461c79abf2684ee31ce27d\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\]/",
                $contents
            );
        } else {
            $this->assertRegularExpression(
                "/^.* $levelNameUpper \(\d\): A $levelName message \[dd.trace_id=\"\d+\" dd.span_id=\"\d+\" dd.service=\"my-service\" dd.version=\"4.2\" dd.env=\"my-env\" level_name=\"$levelName\"\]/",
                $contents
            );
        }
    }

    public function testDebugWithPlaceholders64bit()
    {
        $this->withPlaceholders('debug');
    }

    public function testDebugWithPlaceholders128bit()
    {
        $this->withPlaceholders('debug', true);
    }

    public function testDebugInContext64bit()
    {
        $this->inContext('debug');
    }

    public function testDebugInContext128bit()
    {
        $this->inContext('debug', true);
    }

    public function testDebugAppended64bit()
    {
        $this->appended('debug');
    }

    public function testDebugAppended128bit()
    {
        $this->appended('debug', true);
    }
}
