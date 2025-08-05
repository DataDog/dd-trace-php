<?php

namespace Benchmarks\Integrations;

use DDTrace\Tests\Common\Utils;

class PHPRedisBench
{
    const REDIS_HOST = 'redis-integration';
    const REDIS_PORT = '6379';

    public $redis;

    /**
     * @BeforeMethods({"disablePHPRedisIntegration"})
     * @AfterMethods({"closeConnection"})
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(2)
     */
    public function benchRedisBaseline()
    {
        $this->redisScenario();
    }

    /**
     * @BeforeMethods({"enablePHPRedisIntegration"})
     * @AfterMethods({"closeConnection"})
     * @Revs(100)
     * @Iterations(15)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(10.0)
     * @Warmup(2)
     */
    public function benchRedisOverhead()
    {
        $this->redisScenario();
    }

    public function redisScenario()
    {
        // List of [<command>, [<arg>]
        $commands = [
            ['auth', 'user'],
            ['ping', null],
            ['echo', 'hey'],
            ['rawCommand', 'PING'],
            ['isConnected', null]
        ];

        foreach ($commands as $command) {
            list($method, $arg) = $command;
            null === $arg ? $this->redis->$method() : $this->redis->$method($arg);
        }
    }

    public function disablePHPRedisIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=0'
        ]);
        $this->redis = new \Redis();
        $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT);
        $this->redis->flushAll();
    }

    public function enablePHPRedisIntegration()
    {
        \dd_trace_serialize_closed_spans();
        Utils::putEnvAndReloadConfig([
            'DD_TRACE_ENABLED=1'
        ]);
        $this->redis = new \Redis();
        $this->redis->connect(self::REDIS_HOST, self::REDIS_PORT);
        $this->redis->flushAll();
    }

    public function closeConnection()
    {
        $this->redis->close();
    }
}
