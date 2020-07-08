<?php

namespace DDTrace\Tests\Integrations\PHPRedis;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Util\Versions;
use Exception;
use PHPUnit_Framework_AssertionFailedError;
use Predis\Configuration\Options;

class PHPRedisSandboxedTest extends IntegrationTestCase
{
    const IS_SANDBOX = true;

    private $host = 'redis_integration';
    private $port = '6379';

    /** Redis */
    private $client;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    public function setUp()
    {
        parent::setUp();
        $this->redis = new \Redis();
        $this->redis->connect($this->host);
    }

    public function tearDown()
    {
        $this->redis->flushAll();
        $this->redis->close();
        parent::tearDown();
    }

    /**
     * @dataProvider dataProviderTestConnectionOk
     */
    public function testConnectionOk($method)
    {
        $redis = new \Redis();
        $traces = $this->isolateTracer(function () use ($redis, $method) {
            $redis->$method($this->host);
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

    public function testClose()
    {
        $redis = new \Redis();
        $redis->connect($this->host);
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.close",
                'phpredis',
                'cache',
                "Redis.close"
            ),
        ]);
    }

    /**
     * @dataProvider dataProviderTestMethodsSimpleSpan
     */
    public function testMethodsOnlySpan($method, $arg)
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis, $method, $arg) {
            null === $arg ? $redis->$method() : $redis->$method($arg);
        });

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
            'auth' => ['auth', 'user'],
            'ping' => ['ping', null],
            'echo' => ['echo', 'hey'],
            'save' => ['save', null],
            'bgRewriteAOF' => ['bgRewriteAOF', null],
            'bgSave' => ['bgSave', null],
            'flushAll' => ['flushAll', null],
            'flushDb' => ['flushDb', null],
        ];
    }

    public function testSelect()
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->select(1);
        });

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
     * @dataProvider dataProviderTestStringCommandSet
     */
    public function testStringCommandsSet($method, $args, $expected, $rawCommand, $initial = null)
    {
        $redis = $this->redis;

        if (null !== $initial) {
            $redis->set($args[0], $initial);
        }

        $traces = $this->isolateTracer(function () use ($redis, $method, $args) {
            if (count($args) === 1) {
                $redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            )->withExactTags(['redis.raw_command' => $rawCommand]),
        ]);

        $this->assertSame($expected, $redis->get($args[0]));
    }

    public function dataProviderTestStringCommandSet()
    {
        return [
            [
                'append', // method
                [ 'k1' , 'v1' ], // arguments
                'v1', // expected final value
                'append k1 v1', // raw command
            ],
            [
                'append', // method
                [ 'k1' , 'v1' ], // arguments
                'beforev1', // expected final value
                'append k1 v1', // raw command
                'before', // initial
            ],
            [
                'decr', // method
                [ 'k1' ], // arguments
                '-1', // expected final value
                'decr k1', // raw command
            ],
            [
                'decr', // method
                [ 'k1', '10' ], // arguments
                '-10', // expected final value
                'decr k1 10', // raw command
            ],
            [
                'decrBy', // method
                [ 'k1', '10' ], // arguments
                '-10', // expected final value
                'decrBy k1 10', // raw command
            ],
            [
                'incr', // method
                [ 'k1' ], // arguments
                '1', // expected final value
                'incr k1', // raw command
            ],
            [
                'incr', // method
                [ 'k1', '10' ], // arguments
                '10', // expected final value
                'incr k1 10', // raw command
            ],
            [
                'incrBy', // method
                [ 'k1', '10' ], // arguments
                '10', // expected final value
                'incrBy k1 10', // raw command
            ],
            [
                'incrByFloat', // method
                [ 'k1', '1.3' ], // arguments
                '1.3', // expected final value
                'incrByFloat k1 1.3', // raw command
            ],
            [
                'set', // method
                [ 'k1', 'a' ], // arguments
                'a', // expected final value
                'set k1 a', // raw command
            ],
            [
                'setBit', // method
                [ 'k1', 7, 1 ], // arguments
                '1', // expected final value
                'setBit k1 7 1', // raw command
                '0', // initial "0010 1010"
            ],
            [
                'setEx', // method
                [ 'k1', 1000, 'b' ], // arguments
                'b', // expected final value
                'setEx k1 1000 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'pSetEx', // method
                [ 'k1', 1000, 'b' ], // arguments
                'b', // expected final value
                'pSetEx k1 1000 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'setNx', // method
                [ 'k1', 'b' ], // arguments
                'a', // expected final value
                'setNx k1 b', // raw command
                'a', // initial "0010 1010"
            ],
            [
                'setRange', // method
                [ 'k1', 6, 'redis' ], // arguments
                'Hello redis', // expected final value
                'setRange k1 6 redis', // raw command
                'Hello world', // initial "0010 1010"
            ],
        ];
    }

    public function testMSet()
    {
        $redis = $this->redis;

        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->mSet([ 'k1' => 'v1', 'k2' => 'v2' ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.mSet",
                'phpredis',
                'cache',
                "Redis.mSet"
            )->withExactTags(['redis.raw_command' => 'mSet k1 v1 k2 v2']),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v2', $redis->get('k2'));
    }

    public function testMSetNx()
    {
        $redis = $this->redis;

        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->mSetNx([ 'k1' => 'v1', 'k2' => 'v2' ]);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.mSetNx",
                'phpredis',
                'cache',
                "Redis.mSetNx"
            )->withExactTags(['redis.raw_command' => 'mSetNx k1 v1 k2 v2']),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v2', $redis->get('k2'));
    }

    /**
     * @dataProvider dataProviderTestCommandsWithResult
     */
    public function testCommandsWithResult($method, $args, $expected, $rawCommand, $initial = null)
    {
        $redis = $this->redis;

        if (null !== $initial) {
            \is_array($initial) ? $redis->mSet($initial) : $redis->set($args[0], $initial);
        }

        $result = null;

        $traces = $this->isolateTracer(function () use ($redis, $method, $args, &$result) {
            if (count($args) === 1) {
                $result = $redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "Redis.$method",
                'phpredis',
                'cache',
                "Redis.$method"
            )->withExactTags(['redis.raw_command' => $rawCommand]),
        ]);

        $this->assertSame($expected, $result);
    }

    public function dataProviderTestCommandsWithResult()
    {
        return [
            [
                'get', // method
                [ 'k1' ], // arguments
                false, // expected final value
                'get k1', // raw command
            ],
            [
                'get', // method
                [ 'k1' ], // arguments
                'v1', // expected final value
                'get k1', // raw command
                'v1', // initial
            ],
            [
                'getBit', // method
                [ 'k1', 1 ], // arguments
                1, // expected final value
                'getBit k1 1', // raw command
                '\x7f', // initial
            ],
            [
                'getRange', // method
                [ 'k1', 1, 3], // arguments
                'bcd', // expected final value
                'getRange k1 1 3', // raw command
                'abcdef', // initial
            ],
            [
                'getSet', // method
                [ 'k1', 'v1'], // arguments
                'old', // expected final value
                'getSet k1 v1', // raw command
                'old', // initial
            ],
            [
                'mGet', // method
                [ ['k1', 'k2'] ], // arguments
                [ 'v1', 'v2' ], // expected final value
                'mGet k1 k2', // raw command
                [ 'k1' => 'v1', 'k2' => 'v2'], // initial
            ],
            [
                'getMultiple', // method
                [ ['k1', 'k2'] ], // arguments
                [ 'v1', 'v2' ], // expected final value
                'getMultiple k1 k2', // raw command
                [ 'k1' => 'v1', 'k2' => 'v2'], // initial
            ],
            [
                'strLen', // method
                [ 'k1'], // arguments
                3, // expected final value
                'strLen k1', // raw command
                'old', // initial
            ],
            [
                'del', // method
                [ 'k1'], // arguments
                1, // expected final value
                'del k1', // raw command
                'v1', // initial
            ],
            [
                'delete', // method
                [ 'k1'], // arguments
                1, // expected final value
                'delete k1', // raw command
                'v1', // initial
            ],
        ];
    }

    public function testDumpRestore()
    {
        $redis = $this->redis;
        $redis->set('k1', 'v1');
        error_log('Get: ' . print_r($redis->get('k1'), 1));

        // $traces = $this->isolateTracer(function () use ($redis) {
            $dump = $redis->dump('k1');
            $redis->restore('k2', 1000, $dump);
        // });

        // $this->assertFlameGraph($traces, [
        //     SpanAssertion::build(
        //         "Redis.dump",
        //         'phpredis',
        //         'cache',
        //         "Redis.dump"
        //     )->withExactTags(['redis.raw_command' => 'dump k1']),
        //     SpanAssertion::build(
        //         "Redis.restore",
        //         'phpredis',
        //         'cache',
        //         "Redis.restore"
        //     ),
        // ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v1', $redis->get('k2 '));
    }

    /**
     * @dataProvider dataProviderTestNormalizeArgs
     */
    public function testNormalizeArgs($args, $expected)
    {
        $actual = PHPRedisSandboxedIntegration::normalizeArgs($args);
        $this->assertSame($expected, $actual);
    }

    public function dataProviderTestNormalizeArgs()
    {
        return [
            'no args' => [ [], '' ],
            'one args' => [ ['k1'], 'k1' ],
            'two args' => [ ['k1', 'v1'], 'k1 v1' ],
            'int args' => [ ['k1', 1, 'v1'], 'k1 1 v1' ],
            'indexed array args' => [ [ ['k1', 'k2'] ], 'k1 k2' ],
            'associative array args' => [ [ [ 'k1' => 'v1', 'k2' => 'v2' ] ], 'k1 v1 k2 v2' ],
            'associative numeric array args' => [ [ [ '123' => 'v1', '456' => 'v2' ] ], '123 v1 456 v2' ],
            'mixed array and scalar args' => [ [ ['k1', 'k2'], 1, ['v1', 'v2']], 'k1 k2 1 v1 v2' ],
        ];
    }
}
