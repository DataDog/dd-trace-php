<?php

namespace DDTrace\Tests\Integrations\DeferredLoading;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\AppsecTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use PHPUnit\Framework\TestCase;
use datadog\appsec\AppsecStatus;

final class FilesystemTest extends AppsecTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/index.php';
    }

    protected function assertEvent(string $value, $traces)
    {
       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(1, count($events));
       $this->assertEquals($value, $events[0]["server.io.fs.file"]);
       $this->assertEquals('push_address', $events[0]['eventName']);
       $this->assertTrue($events[0]['rasp']);
       $this->assertGreaterThanOrEqual(0.0, $traces[0][0]['metrics']['_dd.appsec.rasp.duration_ext']);
    }

    public function testFileGetContents()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('Root', '/?function=file_get_contents&path=./index.php'));
            TestCase::assertSame('OK', $response);
        });

        $this->assertEvent('./index.php', $traces);
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
        $this->assertEvent('./index.php', $traces);
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
