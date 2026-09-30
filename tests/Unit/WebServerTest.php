<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Sapi\Sapi;
use DDTrace\Tests\WebServer;

final class WebServerTest extends BaseTestCase
{
    private $temporaryDirectory;

    protected function ddTearDown()
    {
        if ($this->temporaryDirectory) {
            @unlink($this->temporaryDirectory . '/index.php');
            @unlink($this->temporaryDirectory . '/' . WebServer::ERROR_LOG_NAME);
            @rmdir($this->temporaryDirectory);
        }

        parent::ddTearDown();
    }

    public function testCheckErrorsConsumesLogEntriesOnce()
    {
        $server = $this->createWebServer();
        $errorLog = $this->temporaryDirectory . '/' . WebServer::ERROR_LOG_NAME;

        file_put_contents($errorLog, "[ddtrace] [error] first error\n");

        $this->assertSame('[ddtrace] [error] first error', $server->checkErrors());
        $this->assertNull($server->checkErrors());

        file_put_contents($errorLog, "[ddtrace] [warn] second error\n", FILE_APPEND);

        $this->assertSame('[ddtrace] [warn] second error', $server->checkErrors());
        $this->assertNull($server->checkErrors());
    }

    public function testCheckErrorsHandlesTruncatedLog()
    {
        $server = $this->createWebServer();
        $errorLog = $this->temporaryDirectory . '/' . WebServer::ERROR_LOG_NAME;

        file_put_contents($errorLog, "[ddtrace] [error] a deliberately long error\n");
        $server->checkErrors();

        file_put_contents($errorLog, "[ddtrace] [error] new\n");

        $this->assertSame('[ddtrace] [error] new', $server->checkErrors());
        $this->assertNull($server->checkErrors());
    }

    private function createWebServer()
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'ddtrace-webserver-test-');
        @unlink($temporaryFile);
        mkdir($temporaryFile);
        $this->temporaryDirectory = $temporaryFile;

        $indexFile = $this->temporaryDirectory . '/index.php';
        touch($indexFile);

        $server = new WebServer($indexFile);
        $sapi = new WebServerTestSapi();
        $property = new \ReflectionProperty($server, 'sapi');
        $property->setAccessible(true);
        $property->setValue($server, $sapi);

        return $server;
    }
}

final class WebServerTestSapi implements Sapi
{
    public function start()
    {
    }

    public function stop()
    {
    }

    public function isFastCgi()
    {
        return false;
    }

    public function checkErrors()
    {
        return null;
    }
}
