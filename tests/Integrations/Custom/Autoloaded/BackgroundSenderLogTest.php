<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class BackgroundSenderLogTest extends WebFrameworkTestCase
{
    const BGS_FLUSH_INTERVAL_MS = 500;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    private static function getAppErrorLog()
    {
        $index = static::getAppIndexScript();
        $log = \dirname($index) . '/error.log';
        return $log;
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_BETA_SEND_TRACES_VIA_THREAD' => true,
            'DD_TRACE_BGS_ENABLED' => true,
            'DD_TRACE_DEBUG_CURL_OUTPUT' => true,
            'DD_TRACE_ENCODER' => 'msgpack',
            'DD_TRACE_AGENT_FLUSH_INTERVAL' => self::BGS_FLUSH_INTERVAL_MS,
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

    public function testScenario()
    {
        $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create('BGS logs test', '/simple');
            $response = $this->call($spec);

            self::assertEquals('This is a string', $response);

            // Endpoint seemed to work; allow for flush interval to trigger
            \usleep(self::BGS_FLUSH_INTERVAL_MS * 1000);
        });

        $log = self::getAppErrorLog();
        $contents = \file_get_contents($log);
        self::assertContains('[bgs] uploaded', $contents);

        // if this fails, our test may not be reliably clearing the log file
        self::assertNotContains('[bgs] curl_easy_perform() failed', $contents);
    }
}
