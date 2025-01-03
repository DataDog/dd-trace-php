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

    protected static function getEnvs()
        {
            return array_merge(parent::getEnvs(), [
                'DD_APPSEC_RASP_ENABLED' => true
            ]);
        }

    protected function assertEvent(string $value, $traces, $also_ssrf = false)
    {
       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals($also_ssrf ? 2 : 1, count($events));
       $this->assertEquals($value, $events[0]["server.io.fs.file"]);
       if ($also_ssrf) {
        $this->assertEquals($value, $events[1]["server.io.net.url"]);
       }
       $this->assertEquals('push_address', $events[0]['eventName']);
       $this->assertTrue($events[0]['rasp']);
    }

    public function testFileGetContents()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=file_get_contents&path=./index.php'));
            TestCase::assertSame('OK', $response);
        });

        $this->assertEvent('./index.php', $traces, true);
    }

    public function testFileProtocol()
    {
        $file = __DIR__ . '/index.php';
        $traces = $this->tracesFromWebRequest(function () use ($file) {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path=file://'.$file));
            TestCase::assertSame('OK', $response);
        });

        $this->assertEvent('file://'. $file, $traces);
    }

    public function testHttpProtocol()
    {
        $file = 'http://example.com';
        $traces = $this->tracesFromWebRequest(function () use ($file) {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path='.$file));
            TestCase::assertSame('OK', $response);
        });

       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(1, count($events));
       $this->assertEquals($file, $events[0]["server.io.net.url"]);
       $this->assertEquals('push_address', $events[0]['eventName']);
       $this->assertTrue($events[0]['rasp']);
    }

    public function testFilePutContents()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=file_put_contents&path=./somefile'));
            TestCase::assertSame('OK', $response);
        });
       $this->assertEvent('./somefile', $traces);
    }

    public function testFopen()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path=./index.php'));
            TestCase::assertSame('OK', $response);
        });
        $this->assertEvent('./index.php', $traces, true);
    }

    public function testReadFile()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=readfile&path=./dummy'));
            TestCase::assertSame("Dummy file content\nOK", $response);
        });
        $this->assertEvent('./dummy', $traces);
    }
}
