<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class StartupLoggingDisabledTest extends WebFrameworkTestCase
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
            'DD_TRACE_STARTUP_LOGS' => false,
        ]);
    }

    protected function setUp()
    {
        parent::setUp();

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

    public function testLogsNotGeneratedWhenDisabled()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('First request: Startup logs disabled test', '/simple');
            $this->call($spec);
        });

        $contents = \file_get_contents(self::getAppErrorLog());

        self::assertStringNotContains('DATADOG TRACER CONFIGURATION', $contents);
    }
}
