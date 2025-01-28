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

    protected function assertEvent(string $value, $traces, $ssrf = false)
    {
       $events = AppsecStatus::getInstance()->getEvents();
       $this->assertEquals(1, count($events));
       $this->assertEquals(1, count($events[0][0]));
       $key = !$ssrf ? "server.io.fs.file" : "server.io.net.url";
       $this->assertEquals($value, $events[0][0][$key]);
       $this->assertEquals('push_addresses', $events[0]['eventName']);
       $this->assertTrue($events[0]['rasp']);
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

       $this->assertEvent($url, $traces, true);
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
    * @dataProvider wrappedFunctions
    */
    public function testNoProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=./somefile'));

            TestCase::assertSame('OK', str_replace('some content', '', $response));
        });
       $this->assertEvent('./somefile', $traces, false);
    }

    /**
    * @dataProvider wrappedFunctions
    */
    public function testWithFileProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=file://somefile'));
            TestCase::assertSame('OK', $response);
        });
       $this->assertEvent('file://somefile', $traces, false);
    }

    /**
    * @dataProvider wrappedFunctions
    */
    public function testWithHttpProtocol($targetFunction, $ssrf)
    {
        $traces = $this->tracesFromWebRequest(function () use($targetFunction) {
            $response = $this->call(GetSpec::create('Root', '/?function='.$targetFunction.'&path=http://some.url'));
            TestCase::assertSame('OK', $response);
        });
        $events = AppsecStatus::getInstance()->getEvents();
        if ($ssrf) {
            $this->assertEvent('http://some.url', $traces, $ssrf);
        } else { //Only lfi and non valid protocol
            $this->assertEquals(0, count(AppsecStatus::getInstance()->getEvents()));
        }
    }
}
