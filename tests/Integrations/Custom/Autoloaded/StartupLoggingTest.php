<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class StartupLoggingTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    private static function getAppErrorLog()
    {
        return \dirname(static::getAppIndexScript()) . '/startup_logging.log';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_DEBUG' => true, // Startup logs only show in debug mode
            'DD_ENV' => 'my-env',
            'DD_SERVICE' => 'my-service',
            'DD_TRACE_SAMPLE_RATE' => '0.42',
            'DD_TAGS' => 'key1:value1,key2:value2',
            'DD_VERSION' => '4.2',
            'DD_TRACE_RESOURCE_URI_FRAGMENT_REGEX' => '^[a-f0-9]{7}$',
            'DD_TRACE_REPORT_HOSTNAME' => true,
            'DD_TRACE_MEASURE_COMPILE_TIME' => false,
        ]);
    }

    protected function ddSetUp()
    {
        parent::ddSetUp();

        // clear out any previous logs
        $log = self::getAppErrorLog();
        @\unlink($log);
        \touch($log);
    }

    protected static function getInis()
    {
        return array_merge(parent::getInis(), [
            'error_log' => self::getAppErrorLog(),
        ]);
    }

    public function testLogsGeneratedOnFirstRequest()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('First request: Startup logs test', '/simple');
            $this->call($spec);
        });

        $config = self::getStartupLogsArray();

        self::assertTrue($config['debug']);
        self::assertSame('my-env', $config['env']);
        self::assertSame('my-service', $config['service']);
        self::assertSame(0.42, $config['sample_rate']);
        $tags = PHP_VERSION_ID >= 70000 ? ["key1" => "value1", "key2" => "value2"] : 'key1:value1,key2:value2';
        self::assertSame($tags, $config['tags']);
        self::assertSame('4.2', $config['dd_version']);
        self::assertSame('^[a-f0-9]{7}$', $config['uri_fragment_regex']);
        self::assertTrue($config['report_hostname_on_root_span']);
        self::assertFalse($config['measure_compile_time']);
    }

    public function testLogsNotGeneratedAfterFirstRequest()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('Second request: Startup logs test', '/simple');
            $this->call($spec);
        });

        $this->setExpectedException('Exception', 'No JSON found in startup log');

        self::getStartupLogsArray();
    }

    private static function getStartupLogsArray()
    {
        $contents = \file_get_contents(self::getAppErrorLog());
        $lines = explode(PHP_EOL, $contents);

        $target = 'DATADOG TRACER CONFIGURATION - ';
        $json = '';
        foreach ($lines as $line) {
            $pos = strpos($line, $target);
            if ($pos !== false) {
                $json = substr($line, $pos + strlen($target));
                break;
            }
        }

        if (!$json) {
            error_log('[No JSON] Startup log contents: ' . $contents);
            throw new \Exception('No JSON found in startup log');
        }
        if (!$logs = json_decode($json, true)) {
            error_log('[Invalid JSON] Startup log contents: ' . $contents);
            throw new \Exception('Invalid JSON in startup log');
        }

        return $logs;
    }
}
