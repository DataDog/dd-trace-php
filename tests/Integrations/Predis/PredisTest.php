<?php

namespace DDTrace\Tests\Integrations\Predis;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;
use Predis\Configuration\Options;

class PredisTest extends IntegrationTestCase
{
    private $host = 'redis_integration';
    private $port = '6379';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function setUp()
    {
        parent::setUp();
    }

    public function testPredisIntegrationCreatesSpans()
    {
        $traces = $this->inTestScope('custom_redis.test', function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $value = 'bar';

            $client->set('foo', $value);

            $this->assertEquals($client->get('foo'), $value);
        });

        $this->assertCount(1, $traces);
        $trace = $traces[0];

        $this->assertGreaterThan(2, count($trace)); # two Redis operations -> at least 2 spans
    }

    public function testPredisConstructOptionsAsArray()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $this->assertNotNull($client);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Predis.Client.__construct', 'redis', 'cache', 'Predis.Client.__construct')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisConstructOptionsAsObject()
    {
        $options = new Options();

        $traces = $this->isolateTracer(function () use ($options) {
            $client = new \Predis\Client([ "host" => $this->host ], $options);
            $this->assertNotNull($client);
        });


        $this->assertFlameGraph($traces, [
            SpanAssertion::build('Predis.Client.__construct', 'redis', 'cache', 'Predis.Client.__construct')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisConnect()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->connect();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.connect', 'redis', 'cache', 'Predis.Client.connect')
                ->withExactTags($this->baseTags()),
        ]);
    }

    public function testPredisClusterConnect()
    {
        $connectionString = "tcp://{$this->host}";

        $traces = $this->isolateTracer(function () use ($connectionString) {
            $client = new \Predis\Client([ $connectionString, $connectionString, $connectionString ]);
            $client->connect();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.connect', 'redis', 'cache', 'Predis.Client.connect')
                ->withExactTags([]),
        ]);
    }

    public function testPredisSetCommand()
    {
        $traces = $this->isolateTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('foo', 'value');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'cache', 'SET foo value')
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
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::exists('Predis.Client.executeCommand'),
            SpanAssertion::build('Predis.Client.executeCommand', 'redis', 'cache', 'GET key')
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
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->executeRaw(["SET", "key", "value"]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Client.executeRaw', 'redis', 'cache', 'SET key value')
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
            $client = new \Predis\Client([ "host" => $this->host ]);
            list($responsePing, $responseFlush) = $client->pipeline(function ($pipe) {
                $pipe->ping();
                $pipe->flushdb();
            });
            $this->assertInstanceOf('Predis\Response\Status', $responsePing);
            $this->assertInstanceOf('Predis\Response\Status', $responseFlush);
        });

        if (Versions::phpVersionMatches('5') && static::IS_SANDBOX) {
            $exactTags = [];
        } else {
            $exactTags = [
                'redis.pipeline_length' => '2',
            ];
        }

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('Predis.Client.__construct'),
            SpanAssertion::build('Predis.Pipeline.executePipeline', 'redis', 'cache', 'Predis.Pipeline.executePipeline')
                ->withExactTags($exactTags),
        ]);
    }

    public function testLimitedTracesPredisSetCommand()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('foo', 'value');
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracesPredisGetCommand()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            $client->set('key', 'value');
            $this->assertSame('value', $client->get('key'));
        });

        $this->assertEmpty($traces);
    }

    public function testLimitedTracerPredisPipeline()
    {
        $traces = $this->isolateLimitedTracer(function () {
            $client = new \Predis\Client([ "host" => $this->host ]);
            list($responsePing, $responseFlush) = $client->pipeline(function ($pipe) {
                $pipe->ping();
                $pipe->flushdb();
            });
            $this->assertInstanceOf('Predis\Response\Status', $responsePing);
            $this->assertInstanceOf('Predis\Response\Status', $responseFlush);
        });

        $this->assertEmpty($traces);
    }

    private function baseTags()
    {
        return [
            'out.host' => $this->host,
            'out.port' => $this->port,
        ];
    }
}
