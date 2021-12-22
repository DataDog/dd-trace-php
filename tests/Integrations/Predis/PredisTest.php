<?php

namespace DDTrace\Tests\Integrations\Predis;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;
use Predis\Configuration\Options;

final class PredisTest extends IntegrationTestCase
{
    private $host = 'redis_integration';
    private $port = '6379';

    public static function ddSetUpBeforeClass()
    {
        parent::ddSetUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function ddSetUp()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST']);
        parent::ddSetUp();
    }

    protected function ddTearDown()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST']);
        parent::ddTearDown();
    }

    public function testPredisIntegrationCreatesSpans()
    {
        $traces = $this->inTestScope('custom_redis.test', function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $value = 'bar';

            $client->set('foo', $value);

            $this->assertEquals($client->get('foo'), $value);
        });

        $this->assertCount(1, $traces);
        $trace = $traces[0];

        $this->assertGreaterThan(2, count($trace)); # two Redis operations -> at least 2 spans
    }

    /**
     * @dataProvider dataProviderTestConnectionAndServiceInfo
     */
    public function testConnectionAndServiceInfo($firstArgument, $host, $port, $service)
    {
        // With no split by domain
        $traces = $this->isolateTracer(function () use ($firstArgument) {
            if (null === $firstArgument) {
                new \Predis\Client();
            } else {
                new \Predis\Client($firstArgument);
            }
        });
        $span = $traces[0][0];
        $this->assertSame($host, $span['meta']['out.host']);
        $this->assertSame("$port", $span['meta']['out.port']);
        $this->assertSame('redis', $span['service']);

        // With split by domain
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        $traces = $this->isolateTracer(function () use ($firstArgument) {
            if (null === $firstArgument) {
                new \Predis\Client();
            } else {
                new \Predis\Client($firstArgument);
            }
        });
        $span = $traces[0][0];
        $this->assertSame($host, $span['meta']['out.host']);
        $this->assertSame("$port", $span['meta']['out.port']);
        $this->assertSame($service, $span['service']);
    }

    public function dataProviderTestConnectionAndServiceInfo()
    {
        // Test cases from https://github.com/predis/predis#connecting-to-redis
        return [
            'empty' => [ null, '127.0.0.1', 6379, 'redis-127.0.0.1' ],
            'string host array' => [ [ 'host' => '10.10.10.10' ], '10.10.10.10', 6379, 'redis-10.10.10.10' ],
            'string schema host string' => [ 'tcp://10.10.10.10', '10.10.10.10', 6379, 'redis-10.10.10.10' ],
            'string schema host array' => [
                [ 'scheme' => 'tcp', 'host' => '10.10.10.10' ],
                '10.10.10.10',
                6379,
                'redis-10.10.10.10',
            ],
            'string schema host port string' => [ 'tcp://10.10.10.10:7777', '10.10.10.10', 7777, 'redis-10.10.10.10' ],
            'string schema host port array' => [
                [ 'scheme' => 'tcp', 'host' => '10.10.10.10', 'port' => 7777 ],
                '10.10.10.10',
                7777,
                'redis-10.10.10.10',
            ],
            'tls string with query params' => [
                'tls://10.10.10.10?ssl[cafile]=private.pem&ssl[verify_peer]=1',
                '10.10.10.10',
                6379,
                'redis-10.10.10.10',
            ],
            // UDS
            'uds array' => [
                ['scheme' => 'unix', 'path' => '/path/to/redis.sock'],
                '127.0.0.1',
                6379,
                'redis-path-to-redis.sock',
            ],
            'uds string' => [ 'unix:/path/to/redis.sock', '127.0.0.1', 6379, 'redis-path-to-redis.sock' ],
        ];
    }

    public function testPredisConstructOptionsAsArray()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $this->assertNotNull($client);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Predis.Client.__construct', 'redis', 'redis', 'Predis.Client.__construct')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisConstructOptionsAsObject()
    {
        $options = new Options();

        $traces = $this->isolateTracer(function () use ($options) {
            $client = new \Predis\Client(["host" => $this->host], $options);
            $this->assertNotNull($client);
        });


        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Predis.Client.__construct', 'redis', 'redis', 'Predis.Client.__construct')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisConnect()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->connect();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.connect', 'redis', 'redis', 'Predis.Client.connect')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisClusterConnect()
    {
        $connectionString = "tcp://{$this->host}";

        $traces = $this->isolateTracer(function () use ($connectionString) {
            $client = new \Predis\Client([$connectionString, $connectionString, $connectionString]);
            $client->connect();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.connect', 'redis', 'redis', 'Predis.Client.connect')
                ->withExactTags([]),
        ]);
    }

    public function testPredisSetCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->set('foo', 'value');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'redis', 'SET foo value')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'SET foo value',
                    'redis.args_length' => '3',
                ])),
        ]);
    }

    public function testPredisGetCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::exists('Predis.Client.executeCommand'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'redis', 'GET key')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'GET key',
                    'redis.args_length' => '2',
                ])),
        ]);
    }

    public function testPredisRawCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->executeRaw(["SET", "key", "value"]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeRaw', 'redis', 'redis', 'SET key value')
                ->setTraceAnalyticsCandidate()
                ->withExactTags(array_merge([], $this->baseTags(), [
                    'redis.raw_command' => 'SET key value',
                    'redis.args_length' => '3',
                ])),
        ]);
    }

    public function testPredisPipeline()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            list($responsePing, $responseFlush) = $client->pipeline(function ($pipe) {
                $pipe->ping();
                $pipe->flushdb();
            });
            $this->assertInstanceOf('Predis\Response\Status', $responsePing);
            $this->assertInstanceOf('Predis\Response\Status', $responseFlush);
        });

        if (Versions::phpVersionMatches('5')) {
            $exactTags = [];
        } else {
            $exactTags = [
                'redis.pipeline_length' => '2',
            ];
        }

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Pipeline.executePipeline', 'redis', 'redis', 'Predis.Pipeline.executePipeline')
                ->withExactTags($exactTags),
        ]);
    }

    public function testLimitedTracesPredisSetCommand()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->set('foo', 'value');
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracesPredisGetCommand()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracerPredisPipeline()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            list($responsePing, $responseFlush) = $client->pipeline(function ($pipe) {
                $pipe->ping();
                $pipe->flushdb();
            });
            $this->assertInstanceOf('Predis\Response\Status', $responsePing);
            $this->assertInstanceOf('Predis\Response\Status', $responseFlush);
        });

        $this->assertEmpty($traces);
    }

    public function testSplitByHostForSecondarySpans()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client(["host" => $this->host]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertSame('redis-redis_integration', $traces[0][0]['service']);
        $this->assertSame('redis-redis_integration', $traces[0][1]['service']);
        $this->assertSame('redis-redis_integration', $traces[0][2]['service']);
    }

    public function testSplitByHostForErrorSpans()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        $traces = $this->isolateTracer(function () {
            try {
                $client = new \Predis\Client(["host" => "non_existing"]);
                $client->set('key', 'value');
            } catch (\Exception $e) {
                // no action
            }
        });

        $this->assertSame('redis-non_existing', $traces[0][0]['service']);
        $this->assertSame('redis-non_existing', $traces[0][1]['service']);
    }

    private function baseTags()
    {
        return [
            'out.host' => $this->host,
            'out.port' => $this->port,
        ];
    }
}
