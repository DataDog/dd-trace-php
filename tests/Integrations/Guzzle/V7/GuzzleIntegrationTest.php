<?php

namespace DDTrace\Tests\Integrations\Guzzle\V7;

use DDTrace\GlobalTracer;
use DDTrace\Tag;
use DDTrace\Tracer;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use DDTrace\Tests\Common\SpanAssertion;

class GuzzleIntegrationTest extends \DDTrace\Tests\Integrations\Guzzle\V6\GuzzleIntegrationTest
{
    public function testSendRequest()
    {
        $traces = $this->isolateTracer(function () {
            $request = new Request('put', 'http://example.com');
            $this->getMockedClient()->sendRequest($request);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Psr\Http\Client\ClientInterface.sendRequest', 'phpunit', 'http', 'sendRequest')
                ->withExactTags([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'psr18'
                ])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                        ->withExactTags([
                            'http.method' => 'PUT',
                            'http.url' => 'http://example.com',
                            'http.status_code' => '200',
                            'network.destination.name' => 'example.com',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle',
                            '_dd.base_service' => 'phpunit'
                        ]),
                ])
        ]);
    }

    public function testMultiExec()
    {
        if (\PHP_VERSION_ID < 70300) {
            $this->markTestSkipped('This test loose a lot of relevance with old cURL versions');
        }

        $this->putEnvAndReloadConfig([
            'DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true',
            'DD_SERVICE=my-shop',
            'DD_TRACE_GENERATE_ROOT_SPAN=0'
        ]);
        \dd_trace_serialize_closed_spans();

        $this->isolateTracerSnapshot(function () {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $client = $this->getRealClient();
            try {
                $promises = [
                    $client->getAsync('https://google.wrong/', ['http_errors' => false]),
                    $client->getAsync(self::URL . '/redirect-to?url=' . self::URL . '/status/200'),
                    $client->getAsync(self::URL . '/status/200'),
                    $client->getAsync(self::URL . '/status/201'),
                    $client->getAsync(self::URL . '/status/202'),
                    $client->getAsync('https://google.still.wrong/', ['http_errors' => false]),
                    $client->getAsync('https://www.google.com'),
                    $client->getAsync('https://www.google.com'),
                    $client->getAsync('https://www.google.com'),
                ];
                Utils::unwrap($promises);
            } catch (\Exception $e) {
                echo $e->getMessage();
            }

            sleep(1);

            $span->finish();
        }, [
            'start',
            'metrics.php.compilation.total_time_ms',
            'metrics.php.memory.peak_usage_bytes',
            'metrics.php.memory.peak_real_usage_bytes',
            'meta.error.stack',
            'meta._dd.p.tid',
            'meta.curl.appconnect_time_us',
            'meta.curl.connect_time',
            'meta.curl.connect_time_us',
            'meta.curl.download_content_length',
            'meta.curl.filetime',
            'meta.curl.header_size',
            'meta.curl.namelookup_time',
            'meta.curl.namelookup_time_us',
            'meta.curl.pretransfer_time',
            'meta.curl.pretransfer_time_us',
            'meta.curl.redirect_time',
            'meta.curl.redirect_time_us',
            'meta.curl.request_size',
            'meta.curl.speed_download',
            'meta.curl.speed_upload',
            'meta.curl.starttransfer_time',
            'meta.curl.starttransfer_time_us',
            'meta.curl.total_time',
            'meta.curl.total_time_us',
            'meta.curl.upload_content_length',
            'meta.network.bytes_read',
            'meta.network.bytes_written',
            'meta.network.client.ip',
            'meta.network.client.port',
            'meta.network.destination.ip',
            'meta.network.destination.port',
            'meta._dd.base_service',
        ]);
    }
}
