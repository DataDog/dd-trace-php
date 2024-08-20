<?php

namespace DDTrace\Tests\Integrations\PHPRedis\V4;

use DDTrace\Tag;
use DDTrace\Integrations\PHPRedis\PHPRedisIntegration;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use Exception;

class PHPRedisClusterTest extends IntegrationTestCase
{
    protected static $lockedResource = "redis";

    const CONNECTION_1 = 'CONNECTION_1';
    const CONNECTION_1_AS_ARG = 'CONNECTION_1_AS_ARG';
    const A_STRING = 'A_STRING';
    const A_FLOAT = 'A_FLOAT';
    const ARRAY_COUNT_1 = 'ARRAY_COUNT_1';
    const ARRAY_COUNT_2 = 'ARRAY_COUNT_2';
    const ARRAY_COUNT_3 = 'ARRAY_COUNT_3';
    const ARRAY_COUNT_4 = 'ARRAY_COUNT_4';
    const ARRAY_COUNT_5 = 'ARRAY_COUNT_5';

    const SCRIPT_SHA = 'e0e1f9fabfc9d4800c877a703b823ac0578ff8db';

    private $host = 'redis_integration';
    private $clusterIp;
    private $connection1;
    private $connection2;
    private $connection3;

    /** Redis */
    private $redis;
    private $redisSecondInstance;

    public function ddSetUp()
    {
        parent::ddSetUp();
        $this->clusterIp = gethostbyname($this->host);
        $this->connection1 = [$this->clusterIp, 7001];
        $this->connection2 = [$this->clusterIp, 7002];
        $this->connection3 = [$this->clusterIp, 7003];
        $this->redis = new \RedisCluster(null, [
            \implode(':', $this->connection1),
            \implode(':', $this->connection2),
            \implode(':', $this->connection3),
        ]);
        $this->redis->flushAll($this->connection1);
        $this->redis->flushAll($this->connection2);
        $this->redis->flushAll($this->connection3);
    }

    public function ddTearDown()
    {
        $this->redis->close();
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST']);
        \ini_restore('redis.clusters.seeds');
        parent::ddTearDown();
    }

    protected function envsToCleanUpAtTearDown()
    {
        return [
            'DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED',
        ];
    }

    public function testClose()
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis) {
            $redis->close();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.close",
                'phpredis',
                'redis',
                "RedisCluster.close"
            )->withExactTags($this->baseTags()),
        ]);
    }

    /**
     * This function tests ALL the methods that are also tested in the phpredis own test suite. For this reason here
     * we are not testing results (i.e. the fact that the method still works).
     * We are only testing the fact that the span is generated.
     * See ./PHPredisProjectTestsuite folder for original library testsuites.
     *
     * @dataProvider dataProviderTestMethodsSpansOnly
     */
    public function testMethodsSpansOnly($method, $args, $rawCommand)
    {
        $this->redis->set('k1{hash}', 'v1');
        $traces = $this->invokeInIsolatedTracerWithArgs($method, $args);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($this->normalizeRawCommand($method, $rawCommand)))
        ]);
    }

    public function dataProviderTestMethodsSpansOnly()
    {
        return [
            ['del', ['k1{hash}'], 'k1{hash}'],
            ['del', [['k1{hash}', 'k2{hash}']], 'k1{hash} k2{hash}'],
            ['exists', ['k1{hash}'], 'k1{hash}'],
            ['expire', ['k1{hash}', 2], 'k1{hash} 2'],
            ['pexpire', ['k1{hash}', 2], 'k1{hash} 2'],
            ['expireAt', ['k1{hash}', 2], 'k1{hash} 2'],
            ['keys', ['*'], '*'],
            ['scan', [null, self::CONNECTION_1], '0 ' . self::CONNECTION_1_AS_ARG], // the argument is the LONG (reference), initialized to NULL
            ['object', ['encoding', 'k1{hash}'], 'encoding k1{hash}'],
            ['persist', ['k1{hash}'], 'k1{hash}'],
            ['randomKey', [self::CONNECTION_1], self::CONNECTION_1_AS_ARG],
            ['rename', ['k1{hash}', 'k3{hash}'], 'k1{hash} k3{hash}'],
            ['renameNx', ['k1{hash}', 'k3{hash}'], 'k1{hash} k3{hash}'],
            ['type', ['k1{hash}'], 'k1{hash}'],
            ['sort', ['k1{hash}'], 'k1{hash}'],
            ['sort', ['k1{hash}', ['sort' => 'desc']], 'k1{hash} sort desc'],
            ['ttl', ['k1{hash}'], 'k1{hash}'],
            ['pttl', ['k1{hash}'], 'k1{hash}'],
        ];
    }

    /**
     * @dataProvider dataProviderTestMethodsSimpleSpan
     */
    public function testMethodsOnlySpan($method, $arg)
    {
        $redis = $this->redis;
        $traces = $this->isolateTracer(function () use ($redis, $method, $arg) {
            null === $arg ? $redis->$method($this->connection1) : $redis->$method($this->connection1, $arg);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags()),
        ]);
    }

    public function dataProviderTestMethodsSimpleSpan()
    {
        return [
            'ping' => ['ping', null],
            'echo' => ['echo', 'hey'],
            'save' => ['save', null],
            'bgRewriteAOF' => ['bgRewriteAOF', null],
            'flushAll' => ['flushAll', null],
            'flushDb' => ['flushDb', null],
        ];
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
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
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
            [
                'expire', // method
                [ 'k1', 6 ], // arguments
                'value', // expected final value
                'expire k1 6', // raw command
                'value', // initial "0010 1010"
            ],
            [
                'pexpire', // method
                [ 'k1', 6000 ], // arguments
                'value', // expected final value
                'pexpire k1 6000', // raw command
                'value', // initial "0010 1010"
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
                "RedisCluster.mSet",
                'phpredis',
                'redis',
                "RedisCluster.mSet"
            )->withExactTags($this->baseTags('mSet k1 v1 k2 v2')),
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
                "RedisCluster.mSetNx",
                'phpredis',
                'redis',
                "RedisCluster.mSetNx"
            )->withExactTags($this->baseTags('mSetNx k1 v1 k2 v2')),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v2', $redis->get('k2'));
    }

    /**
     * @dataProvider dataProviderTestCommandsWithResult
     */
    public function testCommandsWithResult($method, $args, $expected, $rawCommand, array $initial = [])
    {
        $redis = $this->redis;

        $redis->mSet($initial);

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
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
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
                ['k1' => 'v1'], // initial
            ],
            [
                'getBit', // method
                [ 'k1', 1 ], // arguments
                1, // expected final value
                'getBit k1 1', // raw command
                ['k1' => '\x7f'], // initial
            ],
            [
                'getRange', // method
                [ 'k1', 1, 3], // arguments
                'bcd', // expected final value
                'getRange k1 1 3', // raw command
                ['k1' => 'abcdef'], // initial
            ],
            [
                'getSet', // method
                [ 'k1', 'v1'], // arguments
                'old', // expected final value
                'getSet k1 v1', // raw command
                ['k1' => 'old'], // initial
            ],
            [
                'mGet', // method
                [ ['k1', 'k2'] ], // arguments
                [ 'v1', 'v2' ], // expected final value
                'mGet k1 k2', // raw command
                [ 'k1' => 'v1', 'k2' => 'v2'], // initial
            ],
            [
                'strLen', // method
                [ 'k1'], // arguments
                3, // expected final value
                'strLen k1', // raw command
                ['k1' => 'old'], // initial
            ],
            [
                'del', // method
                [ 'k1'], // arguments
                1, // expected final value
                'del k1', // raw command
                ['k1' => 'v1'], // initial
            ],
            [
                'exists', // method
                [ 'k1'], // arguments
                1, // expected final value
                'exists k1', // raw command
                ['k1' => 'v1'], // initial
            ],
            [
                'pexpireAt', // method
                [ 'k1', 3000 ], // arguments
                true, // expected final value
                'pexpireAt k1 3000', // raw command
                ['k1' => 'v1'], // initial
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestHashFunctions
     */
    public function testHashFunctions($method, $args, $expectedResult, $expectedFinal, $rawCommand)
    {
        $this->redis->hSet('h1', 'k1', 'v1');
        $this->redis->hSet('h1', 'k2', 'v2');
        $this->redis->hSet('h1', 'k3', 3);
        $result = null;

        $traces = $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $this->redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
        ]);

        $this->assertSame($expectedResult, $result);
        $this->assertSame($expectedFinal, $this->redis->hGetAll('h1'));
    }

    public function dataProviderTestHashFunctions()
    {
        $all = [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => '3' ];
        return [
            [
                'hDel', // method
                [ 'h1', 'k1', 'k2' ], // arguments
                2, // expected result
                [ 'k3' => '3' ], // expected final hash value
                'hDel h1 k1 k2', // raw command
            ],
            [
                'hExists', // method
                [ 'h1', 'k1' ], // arguments
                true, // expected result
                $all, // expected final hash value
                'hExists h1 k1', // raw command
            ],
            [
                'hGet', // method
                [ 'h1', 'k1' ], // arguments
                'v1', // expected result
                $all, // expected final hash value
                'hGet h1 k1', // raw command
            ],
            [
                'hGetAll', // method
                [ 'h1' ], // arguments
                $all, // expected result
                $all, // expected final hash value
                'hGetAll h1', // raw command
            ],
            [
                'hIncrBy', // method
                [ 'h1', 'k3', 1 ], // arguments
                4, // expected result
                [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => '4' ], // expected final hash value
                'hIncrBy h1 k3 1', // raw command
            ],
            [
                'hIncrByFloat', // method
                [ 'h1', 'k3', 1.6 ], // arguments
                4.6, // expected result
                [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => '4.6' ], // expected final hash value
                'hIncrByFloat h1 k3 1.6', // raw command
            ],
            [
                'hKeys', // method
                [ 'h1' ], // arguments
                array_keys($all), // expected result
                [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => '3' ], // expected final hash value
                'hKeys h1', // raw command
            ],
            [
                'hLen', // method
                [ 'h1' ], // arguments
                3, // expected result
                $all, // expected final hash value
                'hLen h1', // raw command
            ],
            [
                'hMGet', // method
                [ 'h1', ['k1', 'k3'] ], // arguments
                [ 'k1' => 'v1', 'k3' => '3' ], // expected result
                $all, // expected final hash value
                'hMGet h1 k1 k3', // raw command
            ],
            [
                'hMSet', // method
                [ 'h1', ['k1' => 'a', 'k3' => 'b'] ], // arguments
                true, // expected result
                [ 'k1' => 'a', 'k2' => 'v2', 'k3' => 'b' ], // expected final hash value
                'hMSet h1 k1 a k3 b', // raw command
            ],
            [
                'hSet', // method
                [ 'h1', 'k1', 'a' ], // arguments
                0, // expected result
                [ 'k1' => 'a', 'k2' => 'v2', 'k3' => '3' ], // expected final hash value
                'hSet h1 k1 a', // raw command
            ],
            [
                'hSetNx', // method
                [ 'h1', 'k4', 'a' ], // arguments
                true, // expected result
                [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => '3', 'k4' => 'a' ], // expected final hash value
                'hSetNx h1 k4 a', // raw command
            ],
            [
                'hVals', // method
                [ 'h1' ], // arguments
                [ 'v1', 'v2', '3' ], // expected result
                $all, // expected final hash value
                'hVals h1', // raw command
            ],
            [
                'hScan', // method
                [ 'h1', null ], // arguments
                $all, // expected result
                $all, // expected final hash value
                'hScan h1 0', // raw command
            ],
            [
                'hStrLen', // method
                [ 'h1', 'k1' ], // arguments
                2, // expected result
                $all, // expected final hash value
                'hStrLen h1 k1', // raw command
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestListFunctions
     */
    public function testListFunctions($method, $args, $expectedResult, $expectedFinal, $rawCommand)
    {
        // Using fixed has mechanism: https://redis.io/commands/cluster-keyslot#example
        $this->redis->rPush('l1{fixed_hash}', 'v1');
        $this->redis->rPush('l1{fixed_hash}', 'v2');
        $this->redis->rPush('l2{fixed_hash}', 'z1');
        $result = null;

        $traces = $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $this->redis->$method($args[0], $args[1], $args[2]);
            } elseif (count($args) === 4) {
                $result = $this->redis->$method($args[0], $args[1], $args[2], $args[3]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
        ]);

        $this->assertSame($expectedResult, $result);

        foreach ($expectedFinal as $list => $values) {
            $this->assertCount($this->redis->lLen($list), $values);
            for ($element = 0; $element < count($values); $element++) {
                $this->assertSame($expectedFinal[$list][$element], $this->redis->lGet($list, $element));
            }
        }
    }

    public function dataProviderTestListFunctions()
    {
        $l1 = [ 'v1', 'v2' ];
        $l2 = [ 'z1' ];
        return [
            [
                'blPop', // method
                [ 'l1{fixed_hash}', 'l2{fixed_hash}', 10 ], // arguments
                [ 'l1{fixed_hash}', 'v1' ], // expected result
                [
                    'l1{fixed_hash}' => [ 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'blPop l1{fixed_hash} l2{fixed_hash} 10', // raw command
            ],
            [
                'brPop', // method
                [ 'l1{fixed_hash}', 'l2{fixed_hash}', 10 ], // arguments
                [ 'l1{fixed_hash}', 'v2' ], // expected result
                [
                    'l1{fixed_hash}' => [ 'v1' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'brPop l1{fixed_hash} l2{fixed_hash} 10', // raw command
            ],
            [
                'bRPopLPush', // method
                [ 'l1{fixed_hash}', 'l2{fixed_hash}', 10 ], // arguments
                'v2', // expected result
                [
                    'l1{fixed_hash}' => [ 'v1' ],
                    'l2{fixed_hash}' => [ 'v2', 'z1' ],
                ], // expected final value
                'bRPopLPush l1{fixed_hash} l2{fixed_hash} 10', // raw command
            ],
            [
                'rPopLPush', // method
                [ 'l1{fixed_hash}', 'l2{fixed_hash}' ], // arguments
                'v2', // expected result
                [
                    'l1{fixed_hash}' => [ 'v1' ],
                    'l2{fixed_hash}' => [ 'v2', 'z1' ],
                ], // expected final value
                'rPopLPush l1{fixed_hash} l2{fixed_hash}', // raw command
            ],
            [
                'lIndex', // method
                [ 'l1{fixed_hash}', 0 ], // arguments
                'v1', // expected result
                [
                    'l1{fixed_hash}' => $l1,
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lIndex l1{fixed_hash} 0', // raw command
            ],
            [
                'lGet', // method
                [ 'l1{fixed_hash}', 0 ], // arguments
                'v1', // expected result
                [
                    'l1{fixed_hash}' => $l1,
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lGet l1{fixed_hash} 0', // raw command
            ],
            [
                'lInsert', // method
                [ 'l1{fixed_hash}', \Redis::BEFORE, 'v2', 'v3' ], // arguments
                3, // expected result
                [
                    'l1{fixed_hash}' => ['v1', 'v3', 'v2'],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lInsert l1{fixed_hash} before v2 v3', // raw command
            ],
            [
                'lLen', // method
                [ 'l1{fixed_hash}' ], // arguments
                2, // expected result
                [
                    'l1{fixed_hash}' => $l1,
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lLen l1{fixed_hash}', // raw command
            ],
            [
                'lLen', // method
                [ 'l1{fixed_hash}' ], // arguments
                2, // expected result
                [
                    'l1{fixed_hash}' => $l1,
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lLen l1{fixed_hash}', // raw command
            ],
            [
                'lPop', // method
                [ 'l1{fixed_hash}' ], // arguments
                'v1', // expected result
                [
                    'l1{fixed_hash}' => [ 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lPop l1{fixed_hash}', // raw command
            ],
            [
                'lPush', // method
                [ 'l1{fixed_hash}', 'v3' ], // arguments
                3, // expected result
                [
                    'l1{fixed_hash}' => [ 'v3', 'v1', 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lPush l1{fixed_hash} v3', // raw command
            ],
            [
                'lPushx', // method
                [ 'l1{fixed_hash}', 'v3' ], // arguments
                3, // expected result
                [
                    'l1{fixed_hash}' => [ 'v3', 'v1', 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lPushx l1{fixed_hash} v3', // raw command
            ],
            [
                'lRange', // method
                [ 'l1{fixed_hash}', '0', '0' ], // arguments
                [ 'v1' ], // expected result
                [
                    'l1{fixed_hash}' => $l1,
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lRange l1{fixed_hash} 0 0', // raw command
            ],
            [
                'lRem', // method
                [ 'l1{fixed_hash}', 'v1', '2' ], // arguments
                1, // expected result
                [
                    'l1{fixed_hash}' => [ 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lRem l1{fixed_hash} v1 2', // raw command
            ],
            [
                'lSet', // method
                [ 'l1{fixed_hash}', 1, 'changed' ], // arguments
                true, // expected result
                [
                    'l1{fixed_hash}' => [ 'v1', 'changed' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lSet l1{fixed_hash} 1 changed', // raw command
            ],
            [
                'lTrim', // method
                [ 'l1{fixed_hash}', 1, 2 ], // arguments
                true, // expected result
                [
                    'l1{fixed_hash}' => [ 'v2' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'lTrim l1{fixed_hash} 1 2', // raw command
            ],
            [
                'rPop', // method
                [ 'l1{fixed_hash}' ], // arguments
                'v2', // expected result
                [
                    'l1{fixed_hash}' => [ 'v1' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'rPop l1{fixed_hash}', // raw command
            ],
            [
                'rPush', // method
                [ 'l1{fixed_hash}', 'v3' ], // arguments
                3, // expected result
                [
                    'l1{fixed_hash}' => [ 'v1', 'v2', 'v3' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'rPush l1{fixed_hash} v3', // raw command
            ],
            [
                'rPushX', // method
                [ 'l1{fixed_hash}', 'v3' ], // arguments
                3, // expected result
                [
                    'l1{fixed_hash}' => [ 'v1', 'v2', 'v3' ],
                    'l2{fixed_hash}' => $l2,
                ], // expected final value
                'rPushX l1{fixed_hash} v3', // raw command
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestSetFunctions
     */
    public function testSetFunctions($method, $args, $expectedResult, $expectedFinal, $rawCommand)
    {
        $this->redis->sAdd('s1{hash}', 'v1', 'v2', 'v3');
        $this->redis->sAdd('s2{hash}', 'z1', 'v2' /* 'v2' not a typo */, 'z3');
        $result = null;

        $traces = $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $this->redis->$method($args[0], $args[1], $args[2]);
            } elseif (count($args) === 4) {
                $result = $this->redis->$method($args[0], $args[1], $args[2], $args[3]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
        ]);

        if ($expectedResult === self::A_STRING) {
            $this->assertGreaterThan(0, \strlen($result));
        } elseif ($expectedResult === self::ARRAY_COUNT_1) {
            $this->assertCount(1, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_2) {
            $this->assertCount(2, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_3) {
            $this->assertCount(3, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_4) {
            $this->assertCount(4, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_5) {
            $this->assertCount(5, $result);
        } else {
            $this->assertSame($expectedResult, $result);
        }

        foreach ($expectedFinal as $set => $value) {
            $this->assertSame($this->redis->scard($set), $value);
        }
    }

    public function dataProviderTestSetFunctions()
    {
        return [
            [
                'sAdd', // method
                [ 's1{hash}', 'v4' ], // arguments
                1, // expected result
                [ 's1{hash}' => 4, 's2{hash}' => 3 ], // expected final value
                'sAdd s1{hash} v4', // raw command
            ],
            [
                'sCard', // method
                [ 's1{hash}' ], // arguments
                3, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sCard s1{hash}', // raw command
            ],
            [
                'sDiff', // method
                [ 's1{hash}', 's2{hash}' ], // arguments
                self::ARRAY_COUNT_2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sDiff s1{hash} s2{hash}', // raw command
            ],
            [
                'sDiffStore', // method
                [ 'out{hash}', 's1{hash}', 's2{hash}' ], // arguments
                2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, 'out{hash}' => 2 ], // expected final value
                'sDiffStore out{hash} s1{hash} s2{hash}', // raw command
            ],
            [
                'sInter', // method
                [ 's1{hash}', 's2{hash}' ], // arguments
                [ 'v2' ], // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sInter s1{hash} s2{hash}', // raw command
            ],
            [
                'sInterStore', // method
                [ 'out{hash}', 's1{hash}', 's2{hash}' ], // arguments
                1, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, 'out{hash}' => 1 ], // expected final value
                'sInterStore out{hash} s1{hash} s2{hash}', // raw command
            ],
            [
                'sIsMember', // method
                [ 's1{hash}', 'v3' ], // arguments
                true, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sIsMember s1{hash} v3', // raw command
            ],
            [
                'sMembers', // method
                [ 's1{hash}' ], // arguments
                self::ARRAY_COUNT_3, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sMembers s1{hash}', // raw command
            ],
            [
                'sMove', // method
                [ 's1{hash}', 's2{hash}', 'v1' ], // arguments
                true, // expected result
                [ 's1{hash}' => 2, 's2{hash}' => 4 ], // expected final value
                'sMove s1{hash} s2{hash} v1', // raw command
            ],
            [
                'sPop', // method
                [ 's1{hash}', 2 ], // arguments
                self::ARRAY_COUNT_2, // expected result
                [ 's1{hash}' => 1, 's2{hash}' => 3 ], // expected final value
                'sPop s1{hash} 2', // raw command
            ],
            [
                'sRandMember', // method
                [ 's1{hash}' ], // arguments
                self::A_STRING, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sRandMember s1{hash}', // raw command
            ],
            [
                'sRem', // method
                [ 's1{hash}', 'v1' ], // arguments
                1, // expected result
                [ 's1{hash}' => 2, 's2{hash}' => 3 ], // expected final value
                'sRem s1{hash} v1', // raw command
            ],
            [
                'sUnion', // method
                [ 's1{hash}', 's2{hash}' ], // arguments
                self::ARRAY_COUNT_5, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sUnion s1{hash} s2{hash}', // raw command
            ],
            [
                'sUnionStore', // method
                [ 'out{hash}', 's1{hash}', 's2{hash}' ], // arguments
                5, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, 'out{hash}' => 5 ], // expected final value
                'sUnionStore out{hash} s1{hash} s2{hash}', // raw command
            ],
            [
                'sScan', // method
                [ 's1{hash}', null ], // arguments
                self::ARRAY_COUNT_3, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final value
                'sScan s1{hash} 0', // raw command
            ],
        ];
    }

    /**
     * @dataProvider dataProviderTestSortedSetFunctions
     */
    public function testSortedSetFunctions($method, $args, $expectedResult, $expectedFinal, $rawCommand)
    {
        $aaa = $this->redis->zAdd('s1{hash}', 1, 'v1');
        $this->redis->zAdd('s1{hash}', 0, 'v2');
        $this->redis->zAdd('s1{hash}', 5, 'v3');
        $this->redis->zAdd('s2{hash}', 1, 'z1');
        $this->redis->zAdd('s2{hash}', 0, 'v2' /* 'v2' is not a typo */);
        $this->redis->zAdd('s2{hash}', 5, 'z3');
        $result = null;

        $traces = $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $this->redis->$method($args[0], $args[1], $args[2]);
            } elseif (count($args) === 4) {
                $result = $this->redis->$method($args[0], $args[1], $args[2], $args[3]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
        ]);

        if ($expectedResult === self::A_STRING) {
            $this->assertGreaterThan(0, \strlen($result));
        } elseif ($expectedResult === self::ARRAY_COUNT_1) {
            $this->assertCount(1, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_2) {
            $this->assertCount(2, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_3) {
            $this->assertCount(3, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_4) {
            $this->assertCount(4, $result);
        } elseif ($expectedResult === self::ARRAY_COUNT_5) {
            $this->assertCount(5, $result);
        } else {
            $this->assertEquals($expectedResult, $result);
        }

        foreach ($expectedFinal as $set => $value) {
            $this->assertSame($this->redis->zCount($set, '-inf', '+inf'), $value);
        }
    }

    public function dataProviderTestSortedSetFunctions()
    {
        return [
            [
                'zAdd', // method
                [ 's1{hash}', 1, 'v4' ], // arguments
                1, // expected result
                [ 's1{hash}' => 4, 's2{hash}' => 3 ], // expected final sizes
                'zAdd s1{hash} 1 v4', // raw command
            ],
            [
                'zCard', // method
                [ 's1{hash}' ], // arguments
                3, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final sizes
                'zCard s1{hash}', // raw command
            ],
            [
                'zCount', // method
                [ 's1{hash}', 0, 3 ], // arguments
                2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final sizes
                'zCount s1{hash} 0 3', // raw command
            ],
            [
                'zIncrBy', // method
                [ 's1{hash}', 2.5, 'v1' ], // arguments
                3.5, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final sizes
                'zIncrBy s1{hash} 2.5 v1', // raw command
            ],
            [
                'zInterstore', // method
                [ 'out{hash}', ['s1{hash}', 's2{hash}'] ], // arguments
                1, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, 'out{hash}' => 1 ], // expected final sizes
                'zInterstore out{hash} s1{hash} s2{hash}', // raw command
            ],
            [
                'zRange', // method
                [ 's1{hash}', 0, 1, true ], // arguments
                ['v1' => 1.0, 'v2' => 0.0], // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRange s1{hash} 0 1 true', // raw command
            ],
            [
                'zRangeByScore', // method
                [ 's1{hash}', 0, 2, ['withscores' => true] ], // arguments
                self::ARRAY_COUNT_2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRangeByScore s1{hash} 0 2 withscores true', // raw command
            ],
            [
                'zRevRangeByScore', // method
                [ 's1{hash}', 2, 0 ], // arguments
                self::ARRAY_COUNT_2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRevRangeByScore s1{hash} 2 0', // raw command
            ],
            [
                'zRangeByLex', // method
                [ 's1{hash}', '-', '[v2' ], // arguments
                self::ARRAY_COUNT_2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRangeByLex s1{hash} - [v2', // raw command
            ],
            [
                'zRank', // method
                [ 's1{hash}', 'v2' ], // arguments
                0, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRank s1{hash} v2', // raw command
            ],
            [
                'zRevRank', // method
                [ 's1{hash}', 'v2' ], // arguments
                2, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRevRank s1{hash} v2', // raw command
            ],
            [
                'zRem', // method
                [ 's1{hash}', 'v2' ], // arguments
                1, // expected result
                [ 's1{hash}' => 2, 's2{hash}' => 3, ], // expected final sizes
                'zRem s1{hash} v2', // raw command
            ],
            [
                'zRemRangeByRank', // method
                [ 's1{hash}', 0, 1 ], // arguments
                2, // expected result
                [ 's1{hash}' => 1, 's2{hash}' => 3, ], // expected final sizes
                'zRemRangeByRank s1{hash} 0 1', // raw command
            ],
            [
                'zRemRangeByScore', // method
                [ 's1{hash}', 0, 1 ], // arguments
                2, // expected result
                [ 's1{hash}' => 1, 's2{hash}' => 3, ], // expected final sizes
                'zRemRangeByScore s1{hash} 0 1', // raw command
            ],
            [
                'zRevRange', // method
                [ 's1{hash}', 0, -2 ], // arguments
                [ 'v3', 'v1' ], // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zRevRange s1{hash} 0 -2', // raw command
            ],
            [
                'zPopMax', // method
                [ 's1{hash}', 1 ], // arguments
                [ 'v3' => 5.0 ], // expected result
                [ 's1{hash}' => 2, 's2{hash}' => 3, ], // expected final sizes
                'zPopMax s1{hash} 1', // raw command
            ],
            [
                'zPopMin', // method
                [ 's1{hash}', 1 ], // arguments
                [ 'v2' => 0.0 ], // expected result
                [ 's1{hash}' => 2, 's2{hash}' => 3, ], // expected final sizes
                'zPopMin s1{hash} 1', // raw command
            ],
            [
                'zScore', // method
                [ 's1{hash}', 'v3' ], // arguments
                5, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, ], // expected final sizes
                'zScore s1{hash} v3', // raw command
            ],
            [
                'zunionstore', // method
                [ 'out{hash}', ['s1{hash}', 's2{hash}'] ], // arguments
                5, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3, 'out{hash}' => 5 ], // expected final sizes
                'zunionstore out{hash} s1{hash} s2{hash}', // raw command
            ],
            [
                'zScan', // method
                [ 's1{hash}', null ], // arguments
                self::ARRAY_COUNT_3, // expected result
                [ 's1{hash}' => 3, 's2{hash}' => 3 ], // expected final sizes
                'zScan s1{hash} 0', // raw command
            ],
        ];
    }

    public function testPublish()
    {
        $traces = $this->isolateTracer(function () {
            $this->redis->publish('ch1', 'hi');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.publish",
                'phpredis',
                'redis',
                "RedisCluster.publish"
            )->withExactTags($this->baseTags('publish ch1 hi')),
        ]);
    }

    public function testTransactions()
    {
        $return = null;
        $traces = $this->isolateTracer(function () use (&$return) {
            $return = $this->redis->multi()->set('k1', 'v1')->get('k1')->exec();
        });

        $this->assertSame([true, 'v1'], $return);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.multi",
                'phpredis',
                'redis',
                "RedisCluster.multi"
            )->withExactTags($this->baseTags('multi')),
            SpanAssertion::build(
                "RedisCluster.set",
                'phpredis',
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags('set k1 v1')),
            SpanAssertion::build(
                "RedisCluster.get",
                'phpredis',
                'redis',
                "RedisCluster.get"
            )->withExactTags($this->baseTags('get k1')),
            SpanAssertion::build(
                "RedisCluster.exec",
                'phpredis',
                'redis',
                "RedisCluster.exec"
            )->withExactTags($this->baseTags('exec')),
        ]);
    }

    /**
     * @dataProvider dataProviderTestScriptingFunctions
     */
    public function testScriptingFunctions($method, $args, $expectedResult, /*$expectedFinal, */$rawCommand)
    {
        if ('evalSha' === $method) {
            // Note that it was verified in CI via SSH that:
            //  - flackiness is not due to our tracer as results can vary even without our tracer (possibly due to
            //    interactions with other tests)
            //  - running only this testsuite it passes.
            $this->markTestSkipped('This is flaky in CI. Skipping for now');
            return;
        }

        $sha = $this->redis->script($this->connection1, 'load', 'return 1');
        $this->assertSame(self::SCRIPT_SHA, $sha);
        $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        $result = null;

        $traces = $this->invokeInIsolatedTracerWithArgs($method, $args, $result);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($this->normalizeRawCommand($method, $rawCommand))),
        ]);
        $this->assertEquals($expectedResult, $result);
    }

    public function dataProviderTestScriptingFunctions()
    {
        return [
            [
                'eval', // method
                [ 'return 1' ], // arguments
                1, // expected result
                'return 1', // raw command
            ],
            [
                'evalSha', // method
                [ self::SCRIPT_SHA ], // arguments
                1, // expected result
                self::SCRIPT_SHA, // raw command
            ],
            [
                'script', // method
                [ self::CONNECTION_1, 'load', 'return 2' ], // arguments
                '7f923f79fe76194c868d7e1d0820de36700eb649', // expected result
                self::CONNECTION_1_AS_ARG . ' load return 2', // raw command
            ],
            [
                'getLastError', // method
                [ ], // arguments
                null, // expected result
                '', // raw command
            ],
            [
                'clearLastError', // method
                [ ], // arguments
                true, // expected result
                '', // raw command
            ],
            [
                '_serialize', // method
                [ 'foo' ], // arguments
                's:3:"foo";', // expected result
                'foo', // raw command
            ],
            [
                '_unserialize', // method
                [ 's:3:"foo";' ], // arguments
                'foo', // expected result
                's:3:"foo";', // raw command
            ],
        ];
    }

    public function testDumpRestore()
    {
        $redis = $this->redis;
        $redis->set('k1', 'v1');

        $traces = $this->isolateTracer(function () use ($redis) {
            $dump = $redis->dump('k1');
            $redis->restore('k2', 0, $dump);
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.dump",
                'phpredis',
                'redis',
                "RedisCluster.dump"
            )->withExactTags($this->baseTags('dump k1')),
            SpanAssertion::build(
                "RedisCluster.restore",
                'phpredis',
                'redis',
                "RedisCluster.restore"
            )->withExactTags($this->baseTags()),
        ]);

        $this->assertSame('v1', $redis->get('k1'));
        $this->assertSame('v1', $redis->get('k2'));
    }

    /**
     * @dataProvider dataProviderTestGeocodingFunctions
     */
    public function testGeocodingFunctions($method, $args, $expectedResult, /*$expectedFinal, */ $rawCommand)
    {
        $result = null;
        $initial = $this->redis->geoAdd('existing', -122.431, 37.773, 'San Francisco', -157.858, 21.315, 'Honolulu');

        $traces = $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 0) {
                $result = $this->redis->$method();
            } elseif (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 4) {
                $result = $this->redis->$method($args[0], $args[1], $args[2], $args[3]);
            } elseif (count($args) === 5) {
                $result = $this->redis->$method($args[0], $args[1], $args[2], $args[3], $args[4]);
            } else {
                throw new \Exception('Number of arguments not supported: ' . \count($args));
            }
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.$method",
                'phpredis',
                'redis',
                "RedisCluster.$method"
            )->withExactTags($this->baseTags($rawCommand)),
        ]);

        if ($expectedResult === self::A_FLOAT) {
            $this->assertTrue(\is_float($result));
        } elseif ($expectedResult === self::ARRAY_COUNT_1) {
            $this->assertCount(1, $result);
        } else {
            $this->assertSame($expectedResult, $result);
        }
    }

    public function dataProviderTestGeocodingFunctions()
    {
        return [
            [
                'geoAdd', // method
                [ 'k1', 2.349014, 48.864716, 'Paris' ], // arguments
                1, // expected result
                'geoAdd k1 2.349014 48.864716 Paris', // raw command
            ],
            [
                'geoHash', // method
                [ 'existing', 'San Francisco' ], // arguments
                [ '9q8yyh27wv0' ], // expected result
                'geoHash existing San Francisco', // raw command
            ],
            [
                'geoPos', // method
                [ 'existing', 'San Francisco' ], // arguments
                // This is the definition of 'flakiness'. Let's see how it is in CI and in case we can reconsider.
                self::ARRAY_COUNT_1, // expected result
                'geoPos existing San Francisco', // raw command
            ],
            [
                'geoDist', // method
                [ 'existing', 'San Francisco', 'Honolulu', 'm' ], // arguments
                // This is the definition of 'flakiness'. Let's see how it is in CI and in case we can reconsider.
                self::A_FLOAT, // expected result
                'geoDist existing San Francisco Honolulu m', // raw command
            ],
            [
                'geoRadius', // method
                [ 'existing', -122.431, 37.773, 100, 'km' ], // arguments
                // This is the definition of 'flakiness'. Let's see how it is in CI and in case we can reconsider.
                [ 'San Francisco' ], // expected result
                'geoRadius existing -122.431 37.773 100 km', // raw command
            ],
            [
                'geoRadiusByMember', // method
                [ 'existing', 'San Francisco', 100, 'km' ], // arguments
                // This is the definition of 'flakiness'. Let's see how it is in CI and in case we can reconsider.
                [ 'San Francisco' ], // expected result
                'geoRadiusByMember existing San Francisco 100 km', // raw command
            ],
        ];
    }

    /**
     * Various stream methods are very dependent on each other, so we test them sequentially.
     * This at the cost of test readability, but otherwise it would add too much complexity in setting up fixtures.
     */
    public function testStreamsFunctions()
    {
        // xAdd
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xAdd('s1', '123-456', [ 'k1' => 'v1' ]);
            $this->assertSame('123-456', $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xAdd",
                'phpredis',
                'redis',
                "RedisCluster.xAdd"
            )->withExactTags($this->baseTags('xAdd s1 123-456 k1 v1')),
        ]);

        // xGroup
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xGroup('CREATE', 's1', 'group1', '0');
            $this->assertSame(true, $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xGroup",
                'phpredis',
                'redis',
                "RedisCluster.xGroup"
            )->withExactTags($this->baseTags('xGroup CREATE s1 group1 0')),
        ]);

        // xInfo
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xInfo('GROUPS', 's1');
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xInfo",
                'phpredis',
                'redis',
                "RedisCluster.xInfo"
            )->withExactTags($this->baseTags('xInfo GROUPS s1')),
        ]);

        // xLen
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xLen('s1');
            $this->assertSame(1, $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xLen",
                'phpredis',
                'redis',
                "RedisCluster.xLen"
            )->withExactTags($this->baseTags('xLen s1')),
        ]);

        // xPending
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xPending('s1', 'group1');
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xPending",
                'phpredis',
                'redis',
                "RedisCluster.xPending"
            )->withExactTags($this->baseTags('xPending s1 group1')),
        ]);

        // xRange
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xRange('s1', '-', '+');
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xRange",
                'phpredis',
                'redis',
                "RedisCluster.xRange"
            )->withExactTags($this->baseTags('xRange s1 - +')),
        ]);

        // xRevRange
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xRevRange('s1', '+', '-');
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xRevRange",
                'phpredis',
                'redis',
                "RedisCluster.xRevRange"
            )->withExactTags($this->baseTags('xRevRange s1 + -')),
        ]);

        // xRead
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xRead(['s1' => '$']);
            $this->assertSame([], $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xRead",
                'phpredis',
                'redis',
                "RedisCluster.xRead"
            )->withExactTags($this->baseTags('xRead s1 $')),
        ]);

        // xReadGroup
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xReadGroup('group1', 'consumer1', ['s1' => '>']);
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xReadGroup",
                'phpredis',
                'redis',
                "RedisCluster.xReadGroup"
            )->withExactTags($this->baseTags('xReadGroup group1 consumer1 s1 >')),
        ]);

        // xAck
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xAck('s1', 'group1', ['s1' => '123-456']);
            $this->assertSame(1, $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xAck",
                'phpredis',
                'redis',
                "RedisCluster.xAck"
            )->withExactTags($this->baseTags('xAck s1 group1 s1 123-456')),
        ]);

        // xClaim
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xClaim('s1', 'group1', 'consumer1', 0, ['s1' => '123-456']);
            $this->assertTrue(is_array($result));
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xClaim",
                'phpredis',
                'redis',
                "RedisCluster.xClaim"
            )->withExactTags($this->baseTags('xClaim s1 group1 consumer1 0 s1 123-456')),
        ]);

        // xDel
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xDel('s1', ['123-456']);
            $this->assertSame(1, $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xDel",
                'phpredis',
                'redis',
                "RedisCluster.xDel"
            )->withExactTags($this->baseTags('xDel s1 123-456')),
        ]);

        // xTrim
        $traces = $this->isolateTracer(function () use (&$result) {
            $result = $this->redis->xTrim('s1', 0);
            $this->assertSame(0, $result);
        });
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.xTrim",
                'phpredis',
                'redis',
                "RedisCluster.xTrim"
            )->withExactTags($this->baseTags('xTrim s1 0')),
        ]);
    }

    public function testSetGetWithBinarySafeStringsAsValue()
    {
        $traces = $this->isolateTracer(function () {
            $this->redis->set('k1', $this->getBinarySafeString());
            $this->assertSame($this->getBinarySafeString(), $this->redis->get('k1'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.set",
                'phpredis',
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags())
            ->withExistingTagsNames(['redis.raw_command']),
            SpanAssertion::build(
                "RedisCluster.get",
                'phpredis',
                'redis',
                "RedisCluster.get"
            )->withExactTags($this->baseTags())
            ->withExistingTagsNames(['redis.raw_command']),
        ]);
    }


    public function testSetGetWithBinarySafeStringsAsKey()
    {
        $traces = $this->isolateTracer(function () {
            $this->redis->set($this->getBinarySafeString(), 'v1');
            $this->assertSame('v1', $this->redis->get($this->getBinarySafeString()));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.set",
                'phpredis',
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags())
            ->withExistingTagsNames(['redis.raw_command']),
            SpanAssertion::build(
                "RedisCluster.get",
                'phpredis',
                'redis',
                "RedisCluster.get"
            )->withExactTags($this->baseTags())
            ->withExistingTagsNames(['redis.raw_command']),
        ]);
    }

    /**
     * @dataProvider dataProviderTestNormalizeArgs
     */
    public function testNormalizeArgs($args, $expected)
    {
        $actual = PHPRedisIntegration::normalizeArgs($args);
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

    public function testBinarySafeStringsCanBeNormalized()
    {
        // Based on redis docs, key and values can be 'binary-safe' strings, so we need to make sure they are
        // correctly converted to a placeholder.
        $this->assertSame(106, strlen(PHPRedisIntegration::normalizeArgs([$this->getBinarySafeString(), 'v1'])));
    }

    public function getBinarySafeString()
    {
        // Binary-safe string made of random bytes.
        $bytes = [
            '01011001', '11010001', '00001111', '11101001', '10010100', '01010110', '10111110', '00111011',
            '10001101', '11100010', '00110110', '10101100', '11000100', '01010100', '10100010', '11110001',
            '01100101', '01000001', '01100010', '01001000', '01000110', '01011001', '11001000', '10111010',
            '01100000', '11010111', '00001111', '00011011', '10011011', '11010011', '01011110', '01110100',
            '10000111', '11001001', '10000111', '11011111', '11010000', '00111000', '10001011', '11110000',
            '01111111', '00110010', '00010010', '11101110', '00011101', '11101001', '11000111', '10111101',
            '11000001', '00110011', '01101001', '11010010', '01011011', '01110011', '11000111', '00101101',
            '01010001', '11010110', '00000001', '11101111', '00100001', '00111110', '11100110', '10111011',
            '11010010', '00100101', '10101001', '01100101', '01101011', '10000101', '11111000', '00000101',
            '01000101', '01101011', '11100101', '01100001', '10010001', '01011011', '01110100', '11101010',
            '01001111', '01011011', '00010001', '00110100', '11001000', '00001101', '01000010', '00101011',
            '11010010', '00001100', '10111001', '10100001', '01011001', '10100100', '10100000', '10001110',
            '11010111', '10011011', '00111001', '01100010', '11001000', '00001101', '01000010', '00101011',
        ];
        $binarySafeString = null;
        foreach ($bytes as $binary) {
            $binarySafeString .= pack('H*', dechex(bindec($binary)));
        }
        return $binarySafeString;
    }

    public function testSplitByDomainWithClusterName()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        $redis = null;
        $traces = $this->isolateTracer(function () use (&$redis) {
            $redis = new \RedisCluster('cluster_name', [
                \implode(':', $this->connection1),
                \implode(':', $this->connection2),
                \implode(':', $this->connection3),
            ]);
            $redis->set('key', 'value');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.__construct",
                'redis-cluster_name',
                'redis',
                "RedisCluster.__construct"
            )->withExactTags([
                'out.host' => $this->connection1[0], 'out.port' => $this->connection1[1], Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'phpredis', Tag::DB_SYSTEM => 'redis',
            ]),
            SpanAssertion::build(
                "RedisCluster.set",
                'redis-cluster_name',
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags('set key value', false, false), ['_dd.cluster.name' => 'cluster_name'])
        ]);

        $redis->close();
    }

    public function testSplitByDomainWithClusterNameAndSeeds()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        \ini_set(
            'redis.clusters.seeds',
            sprintf('cluster_name[]=%s', \implode(':', $this->connection1))
        );
        $redis = null;
        $traces = $this->isolateTracer(function () use (&$redis) {
            $redis = new \RedisCluster('cluster_name');
            $redis->set('key', 'value');
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.__construct",
                'redis-cluster_name',
                'redis',
                "RedisCluster.__construct"
            )->withExactTags([
                'out.host' => $this->connection1[0], 'out.port' => $this->connection1[1], Tag::SPAN_KIND => 'client',
                Tag::COMPONENT => 'phpredis', Tag::DB_SYSTEM => 'redis',
            ]),
            SpanAssertion::build(
                "RedisCluster.set",
                'redis-cluster_name',
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags('set key value', false, false), ['_dd.cluster.name' => 'cluster_name'])
        ]);

        $redis->close();
    }

    public function testSplitByDomainWithFirstNodeIP()
    {
        $this->putEnvAndReloadConfig(['DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST=true']);
        $redis = null;
        $traces = $this->isolateTracer(function () use (&$redis) {
            $redis = new \RedisCluster(null, [
                \implode(':', $this->connection1),
                \implode(':', $this->connection2),
                \implode(':', $this->connection3),
            ]);
            $redis->set('key', 'value');
        });

        $serviceName = 'redis-' . $this->connection1[0] . '-' . $this->connection1[1];
        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                "RedisCluster.__construct",
                $serviceName,
                'redis',
                "RedisCluster.__construct"
            )->withExactTags($this->baseTags(null, false, false), ['out.host' => $this->connection1[0], 'out.port' => $this->connection1[1]]),
            SpanAssertion::build(
                "RedisCluster.set",
                $serviceName,
                'redis',
                "RedisCluster.set"
            )->withExactTags($this->baseTags('set key value'))
        ]);

        $redis->close();
    }

    private function invokeInIsolatedTracerWithArgs($method, $args, &$result = null)
    {
        // Replacing args with connections, as dataproviders run before anything else, including setup
        foreach ($args as &$arg) {
            if (self::CONNECTION_1 === $arg) {
                $arg = $this->connection1;
            }
        }
        return $this->isolateTracer(function () use ($method, $args, &$result) {
            if (count($args) === 0) {
                $result = $this->redis->$method();
            } elseif (count($args) === 1) {
                $result = $this->redis->$method($args[0]);
            } elseif (count($args) === 2) {
                $result = $this->redis->$method($args[0], $args[1]);
            } elseif (count($args) === 3) {
                $result = $this->redis->$method($args[0], $args[1], $args[2]);
            } else {
                throw new \Exception('number of args not supported: ' . count($args));
            }
        });
    }

    private function normalizeRawCommand($method, $rawCommand)
    {
        // Connections are replaced here because:
        //  - we need actual IPs to identify nodes in the cluster
        //  - cluster IPs are resolved from DNS during setup
        //  - data provider runs before setup
        $rawCommand = \str_replace(self::CONNECTION_1_AS_ARG, \implode(' ', $this->connection1), $rawCommand);
        return empty($rawCommand) ? $method : "$method $rawCommand";
    }

    protected function baseTags($rawCommand = null, $expectPeerService = false, $hasFirstConfiguredHost = true)
    {
        $tags = [
            Tag::SPAN_KIND => 'client',
            Tag::COMPONENT => 'phpredis',
            Tag::DB_SYSTEM => 'redis',
        ];

        if ($hasFirstConfiguredHost) {
            $tags['_dd.first.configured.host'] = $this->clusterIp;
        }

        if ($rawCommand) {
            $tags['redis.raw_command'] = $rawCommand;
        }

        if ($expectPeerService) {
            if ($hasFirstConfiguredHost) {
                $tags['peer.service'] = $this->clusterIp;
                $tags['_dd.peer.service.source'] = '_dd.first.configured.host';
            } else {
                $tags['peer.service'] = 'cluster_name';
                $tags['_dd.peer.service.source'] = '_dd.cluster.name';
            }
        }

        return $tags;
    }
}
