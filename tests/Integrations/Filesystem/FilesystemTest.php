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

    protected static function getEnvs()
        {
            return array_merge(parent::getEnvs(), [
                'DD_APPSEC_RASP_ENABLED' => true
            ]);
        }

    protected function assertEvent(string $value, $traces, string $rasp_rule)
    {
       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(1, count($events));
       $this->assertEquals(1, count($events[0][0]));
       $key = $rasp_rule == "lfi" ? "server.io.fs.file" : "server.io.net.url";
       $this->assertEquals($value, $events[0][0][$key]);
       $this->assertEquals('push_addresses', $events[0]['eventName']);
       $this->assertEquals($rasp_rule, $events[0]['rasp_rule']);
    }

    public function ssrfProtocols()
    {
        return [
            ['http'],
            ['https'],
            ['ftp'],
            ['ftps']
        ];
    }

    /**
    * @dataProvider ssrfProtocols
    */
    public function testSsrfProtocols($protocol)
    {
        $url = $protocol.'://example.com';
        $traces = $this->tracesFromWebRequest(function () use ($url) {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path='.$url));
            TestCase::assertSame('OK', $response);
        });

       $this->assertEvent($url, $traces, "ssrf");
    }

    public function testInvalidProtocol()
    {
        $url = 'bad://example.com';
        $traces = $this->tracesFromWebRequest(function () use ($url) {
            $response = $this->call(GetSpec::create('Root', '/?function=fopen&path='.$url));
            TestCase::assertSame('OK', $response);
        });

       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(0, count($events));
    }

    public function wrappedFunctions()
    {
        return [
            ['file_get_contents', 'ssrf' => true],
            ['file_put_contents', 'ssrf' => false],
            ['fopen', 'ssrf' => true],
            ['readfile', 'ssrf' => false],
        ];
    }

    /**
    * With no protocol all default to files system wrapper and therefore lfi
    * @dataProvider wrappedFunctions
    */
    public function testNoProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=./somefile'));
            //The str_replace replace is because the content of the file is sent to the output on some functions only
            TestCase::assertSame('OK', str_replace('some content', '', $response));
        });
       $this->assertEvent('./somefile', $traces, "lfi");
    }

    /**
    * With file protocol always use LFI
    * @dataProvider wrappedFunctions
    */
    public function testWithFileProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=file://somefile'));
            TestCase::assertSame('OK', $response);
        });
       $this->assertEvent('file://somefile', $traces, "lfi");
    }

    /**
    * HTTP protocol is valid for SSRF
    * @dataProvider wrappedFunctions
    */
    public function testWithHttpProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=http://some.url'));
            TestCase::assertSame('OK', $response);
        });
        if ($ssrf) {
            $this->assertEvent('http://some.url', $traces, "ssrf");
        } else {
            $this->assertEquals(0, count(AppsecStatus::getInstance()->getEvents()));
        }
    }
}
