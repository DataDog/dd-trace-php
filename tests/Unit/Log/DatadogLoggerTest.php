<?php

namespace DDTrace\Tests\Unit\Log;

use DDTrace\Log\DatadogLogger;
use DDTrace\Log\LogLevel;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\Utils;

class DatadogLoggerTest extends BaseTestCase
{
    public function ddSetUp()
    {
        parent::ddTearDownAfterClass();
        shell_exec("rm -f /tmp/php-error.log");
        ini_set("log_errors", 1);
        ini_set("error_log", "/tmp/php-error.log");
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_GENERATE_ROOT_SPAN=0',
        ]);
    }

    public function envsToCleanUpAtTearDown()
    {
        return [
            'DD_LOGS_INJECTION',
            'DD_TRACE_LOG_FILE',
        ];
    }

    public function testBasicLog()
    {
        (new DatadogLogger())->info("oui");
        $output = file_get_contents("/tmp/php-error.log");
        $record = json_decode($output, true);
        $this->assertSame("oui", $record["message"]);
        $this->assertSame("info", $record["status"]);
        $this->assertRegularExpression("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{6}\+\d{2}:\d{2}/", $record["timestamp"]);
    }

    public function testBasicDirectLogCall()
    {
        (new DatadogLogger())->log(LogLevel::ALERT, "oui", ["foo" => "string", "bar" => 42, "baz" => true, "qux" => null]);
        $output = file_get_contents("/tmp/php-error.log");
        $record = json_decode($output, true);
        $this->assertSame("oui", $record["message"]);
        $this->assertSame("string", $record["foo"]);
        $this->assertSame(42, $record["bar"]);
        $this->assertSame(true, $record["baz"]);
        $this->assertSame(null, $record["qux"]);
        $this->assertSame("alert", $record["status"]);
        $this->assertRegularExpression("/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}.\d{6}\+\d{2}:\d{2}/", $record["timestamp"]);
    }

    public function testLogInjection()
    {
        $this->putEnvAndReloadConfig([
            'DD_LOGS_INJECTION=1',
        ]);

        $activeSpan = \DDTrace\start_span();
        $expectedTraceId = \DDTrace\logs_correlation_trace_id();
        $expectedSpanId = \dd_trace_peek_span_id();
        (new DatadogLogger())->info("oui");
        \DDTrace\close_span();

        $output = file_get_contents("/tmp/php-error.log");
        $record = json_decode($output, true);
        $this->assertSame($expectedTraceId, $record["dd.trace_id"]);
        $this->assertSame($expectedSpanId, $record["dd.span_id"]);
    }

    public function testFileUrl()
    {
        $url = "file:///tmp/php-error.log";
        (new DatadogLogger($url))->info("oui");
        $this->assertFileExists("/tmp/php-error.log");
        $output = file_get_contents("/tmp/php-error.log");
        $record = json_decode($output, true);
        $this->assertSame("oui", $record["message"]);
    }

    public function testInvalidFileUrl()
    {
        $url = "any:///tmp/php-error.log";
        (new DatadogLogger($url))->info("oui");
        $this->assertFileNotExist("/tmp/php-error.log");
    }

    // ---

    public function testInvalidLogLevel()
    {
        (new DatadogLogger())->log("invalid", "oui");
        $this->assertSame("", file_get_contents("/tmp/php-error.log"));
    }

    public function testLogInjectionWithoutActiveSpans()
    {
        $this->putEnvAndReloadConfig([
            'DD_LOGS_INJECTION=1',
        ]);

        (new DatadogLogger())->info("oui");
        $output = file_get_contents("/tmp/php-error.log");
        $record = json_decode($output, true);
        $this->assertArrayNotHasKey("dd.trace_id", $record);
        $this->assertArrayNotHasKey("dd.span_id", $record);
    }

    public function testLogFileToNonWritableDirectory()
    {
        ini_set("error_log", "/dev/null/php-error.log");
        (new DatadogLogger())->info("oui");
        $this->assertFileNotExist("/dev/null/php-error.log");
    }
}
