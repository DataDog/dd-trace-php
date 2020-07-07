<?php

namespace DDTrace\Tests\Integrations\PHPRedis;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;
use Exception;
use PHPUnit_Framework_AssertionFailedError;
use Predis\Configuration\Options;

class PHPRedisTest extends IntegrationTestCase
{
    const IS_SANDBOX = true;

    private $host = 'redis_integration';
    private $port = '6379';

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    /**
     * @dataProvider dataProviderTestConnectionOk
     */
    public function testConnectionOk($method)
    {
        $redis = new \Redis();
        $host = $this->host;
        $traces = $this->isolateTracer(function () use ($redis, $method, $host) {
            $redis->$method($host);
        });
        $redis->close();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            )->withExactTags([
                'out.host' => $this->host,
                'out.port' => $this->port,
            ]),
        ]);
    }

    public function dataProviderTestConnectionOk()
    {
        return [
            'connect' => ['connect'],
            'pconnect' => ['pconnect'],
            'open' => ['open'],
            'popen' => ['popen'],
        ];
    }

    /**
     * @dataProvider dataProviderTestConnectionError
     */
    public function testConnectionError($host, $port, $method)
    {
        $redis = new \Redis();
        $traces = $this->isolateTracer(function () use ($redis, $method, $host, $port) {
            try {
                if (null !== $host && null !== $port) {
                    $redis->$method($host, $port);
                } elseif (null !== $host) {
                    $redis->$method($host);
                }
            } catch (\Exception $e) {
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            )
            ->setError()
            ->withExactTags([
                'out.host' => $host,
                'out.port' => $port ?: $this->port,
            ])
            ->withExistingTagsNames(['error.msg', 'error.stack']),
        ]);
    }

    public function dataProviderTestConnectionError()
    {
        return [
            // Unreachable
            'connect host unreachable' => ['not_existing_host', null, 'connect'],
            'open host unreachable' => ['not_existing_host', null, 'open'],
            'pconnect host unreachable' => ['not_existing_host', null, 'pconnect'],
            'popen host unreachable' => ['not_existing_host', null, 'popen'],
            // Not listening
            'connect host not listening' => ['127.0.01', null, 'connect'],
            'open host not listening' => ['127.0.01', null, 'open'],
            'pconnect host not listening' => ['127.0.01', null, 'pconnect'],
            'popen host not listening' => ['127.0.01', null, 'popen'],
            // Wrong port
            'connect wrong port' => [$this->host, 1111, 'connect'],
            'open wrong port' => [$this->host, 1111, 'open'],
            'pconnect wrong port' => [$this->host, 1111, 'pconnect'],
            'popen wrong port' => [$this->host, 1111, 'popen'],
        ];
    }

    /**
     * @dataProvider dataProviderTestMethodsSimpleSpan
     */
    public function testMethodsOnlySpan($method, $arg)
    {
        $redis = new \Redis();
        $redis->connect($this->host);
        $traces = $this->isolateTracer(function () use ($redis, $method, $arg) {
            null === $arg ? $redis->$method() : $redis->$method($arg);
        });
        $redis->close();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            ),
        ]);
    }

    public function dataProviderTestMethodsSimpleSpan()
    {
        return [
            'close' => ['close', null],
            'auth' => ['auth', 'user'],
            'ping' => ['ping', null],
            'echo' => ['echo', 'hey'],
            'save' => ['save', null],
            // These do not work: callback is invoked but Span is empty and not sent.
            // 'bgRewriteAOF' => ['bgRewriteAOF', null],
            // 'bgSave' => ['bgSave', null],
            // 'flushAll' => ['flushAll', null],
            // 'flushDb' => ['flushDb', null],
        ];
    }

    public function testSelect()
    {
        $redis = new \Redis();
        $redis->connect($this->host);
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->select(1);
        });
        $redis->close();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.select",
                'phpredis',
                'cache',
                "Redis.select"
            )->withExactTags(['db.index' => '1']),
        ]);
    }

    /**
     * @dataProvider dataProviderTestStringCommand
     */
    public function testStringCommand($method, $args)
    {
        $redis = new \Redis();
        $redis->connect($this->host);
        $traces = $this->isolateTracer(function () use ($redis, $method, $args) {
            if (count($args) === 1) {
                $redis->$method($args[0]);
            } else {
            }
        });
        $redis->close();

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            ),
        ]);
    }

    public function dataProviderTestStringCommand()
    {
        return [
            'append' => ['append', ['k1', 'v1']],
        ];
    }
}
