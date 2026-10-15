<?php

namespace DDTrace\Tests\Integrations\Guzzle\Latest;

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
                ->withExactAttributes([
                    'http.method' => 'PUT',
                    'http.url' => 'http://example.com',
                    'http.status_code' => '200',
                    'network.destination.name' => 'example.com',
                    TAG::SPAN_KIND => 'client',
                    Tag::COMPONENT => 'psr18',
                ])
                ->withChildren([
                    SpanAssertion::build('GuzzleHttp\Client.transfer', 'guzzle', 'http', 'transfer')
                        ->withExactAttributes([
                            'http.method' => 'PUT',
                            'http.url' => 'http://example.com',
                            'http.status_code' => '200',
                            'network.destination.name' => 'example.com',
                            TAG::SPAN_KIND => 'client',
                            Tag::COMPONENT => 'guzzle',
                            '_dd.svc_src' => 'guzzle',
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
                    $client->getAsync('https://www.google.com/'),
                    $client->getAsync('https://www.google.com/'),
                    $client->getAsync('https://www.google.com/'),
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
            'metrics.curl.appconnect_time_us',
            'meta.curl.cainfo',
            'meta.curl.capath',
            'metrics.curl.connect_time',
            'metrics.curl.connect_time_us',
            'metrics.curl.download_content_length',
            'meta.curl.effective_method', // PHP 8.2+ only
            'metrics.curl.filetime',
            'metrics.curl.header_size',
            'metrics.curl.namelookup_time',
            'metrics.curl.namelookup_time_us',
            'metrics.curl.pretransfer_time',
            'metrics.curl.pretransfer_time_us',
            'metrics.curl.redirect_time',
            'metrics.curl.redirect_time_us',
            'metrics.curl.request_size',
            'metrics.curl.speed_download',
            'metrics.curl.speed_upload',
            'metrics.curl.starttransfer_time',
            'metrics.curl.starttransfer_time_us',
            'metrics.curl.total_time',
            'metrics.curl.total_time_us',
            'metrics.curl.upload_content_length',
            'metrics.network.bytes_read',
            'metrics.network.bytes_written',
            'meta.network.client.ip',
            'metrics.network.client.port',
            'meta.network.destination.ip',
            'metrics.network.destination.port',
            'meta._dd.base_service',
        ]);
    }
}
