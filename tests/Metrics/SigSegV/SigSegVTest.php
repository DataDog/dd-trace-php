<?php

namespace DDTrace\Tests\Metrics\SigSegV;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\WebServer;
use PHPUnit\Framework\AssertionFailedError;

class SigSegVTest extends WebFrameworkTestCase
{
    protected static function getEnvs()
    {
        return \array_merge(parent::getEnvs(), ['DD_TRACE_HEALTH_METRICS_ENABLED' => 1]);
    }

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../Frameworks/Custom/Version_Not_Autoloaded/sigsegv.php';
    }

    protected function ddSetUp()
    {
        if (!extension_loaded('posix')) {
            $this->markTestSkipped(
                'The posix extension is not available.'
            );
        }
        parent::ddSetUp();
        @unlink(__DIR__ . '/../../Frameworks/Custom/Version_Not_Autoloaded/' . WebServer::ERROR_LOG_NAME);

        $this->checkWebserverErrors = false;
    }

    /**
     * @retryAttempts 0
     */
    public function testGet()
    {
        $log = __DIR__ . '/../../Frameworks/Custom/Version_Not_Autoloaded/' . WebServer::ERROR_LOG_NAME;
        self::assertFileNotExists($log);

        $spec = GetSpec::create('sigsegv', '/sigsegv.php');
        try {
            $this->call($spec);
        } catch (AssertionFailedError $e) {
        }

        self::assertFileExists($log);
        $contents = \file_get_contents($log);
        self::assertRegExp("/.*Segmentation fault/", $contents);
    }
}
