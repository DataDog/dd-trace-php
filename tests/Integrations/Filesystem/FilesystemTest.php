<?php

namespace DDTrace\Tests\Integrations\DeferredLoading;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use PHPUnit\Framework\TestCase;
use datadog\appsec\AppsecStatus;

final class FilesystemTest extends AppsecTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/index.php';
    }

    protected function assertEvent(string $value)
    {
       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(1, count($events));
       $this->assertEquals($value, $events[0]["server.io.fs.file"]);
       $this->assertEquals('push_address', $events[0]['eventName']);
    }

    public function testFileGetContents()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=file_get_contents&path=./index.php'));
            TestCase::assertSame('OK', $response);
        });

        $this->assertEvent('./index.php');
    }

    public function testFilePutContents()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=file_put_contents&path=./somefile'));
            TestCase::assertSame('OK', $response);
        });
       $this->assertEvent('./somefile');
    }

    public function testFopen()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path=./index.php'));
            TestCase::assertSame('OK', $response);
        });
        $this->assertEvent('./index.php');
    }

    public function testReadFile()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=readfile&path=./dummy'));
            TestCase::assertSame("Dummy file content\nOK", $response);
        });
        $this->assertEvent('./dummy');
    }

    public function testStat()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=stat&path=./dummy'));
            TestCase::assertSame("OK", $response);
        });
        $this->assertEvent('./dummy');
    }

    public function testLstat()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=lstat&path=./dummy'));
            TestCase::assertSame("OK", $response);
        });
        $this->assertEvent('./dummy');
    }
}
