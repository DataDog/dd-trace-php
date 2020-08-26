<?php

namespace DDTrace\Integrations\PHPRedis;

use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class PHPRedisSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'phpredis';

    const NOT_SET = '__DD_NOT_SET__';
    const CMD_MAX_LEN = 1000;
    const VALUE_TOO_LONG_MARK = '...';
    const VALUE_MAX_LEN = 100;
    const VALUE_PLACEHOLDER = "?";

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        $traceConnectOpen = function (SpanData $span, $args) {
            PHPRedisSandboxedIntegration::enrichSpan($span);
            $span->meta[Tag::TARGET_HOST] = (isset($args[0]) && \is_string($args[0])) ? $args[0] : '127.0.0.1';
            $span->meta[Tag::TARGET_PORT] = (isset($args[1]) && \is_numeric($args[1])) ? $args[1] : 6379;
        };
        \DDTrace\trace_method('Redis', 'connect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'pconnect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'open', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'popen', $traceConnectOpen);

        $this->traceMethodNoArgs('close');
        $this->traceMethodNoArgs('auth');
        $this->traceMethodNoArgs('ping');
        $this->traceMethodNoArgs('echo');
        $this->traceMethodNoArgs('bgRewriteAOF');
        $this->traceMethodNoArgs('bgSave');
        $this->traceMethodNoArgs('flushAll');
        $this->traceMethodNoArgs('flushDb');
        $this->traceMethodNoArgs('save');
        // We do not trace arguments of restore as they are binary
        $this->traceMethodNoArgs('restore');

        \DDTrace\trace_method('Redis', 'select', function (SpanData $span, $args) {
            PHPRedisSandboxedIntegration::enrichSpan($span);
            if (isset($args[0]) && \is_numeric($args[0])) {
                $span->meta['db.index'] = $args[0];
            }
        });

        $this->traceMethodAsCommand('swapdb');

        $this->traceMethodAsCommand('append');
        $this->traceMethodAsCommand('decr');
        $this->traceMethodAsCommand('decrBy');
        $this->traceMethodAsCommand('get');
        $this->traceMethodAsCommand('getBit');
        $this->traceMethodAsCommand('getRange');
        $this->traceMethodAsCommand('getSet');
        $this->traceMethodAsCommand('incr');
        $this->traceMethodAsCommand('incrBy');
        $this->traceMethodAsCommand('incrByFloat');
        $this->traceMethodAsCommand('mGet');
        $this->traceMethodAsCommand('getMultiple');
        $this->traceMethodAsCommand('mSet');
        $this->traceMethodAsCommand('mSetNx');
        $this->traceMethodAsCommand('set');
        $this->traceMethodAsCommand('setBit');
        $this->traceMethodAsCommand('setEx');
        $this->traceMethodAsCommand('pSetEx');
        $this->traceMethodAsCommand('setNx');
        $this->traceMethodAsCommand('setRange');
        $this->traceMethodAsCommand('strLen');

        $this->traceMethodAsCommand('del');
        $this->traceMethodAsCommand('delete');
        $this->traceMethodAsCommand('dump');
        $this->traceMethodAsCommand('exists');
        $this->traceMethodAsCommand('keys');
        $this->traceMethodAsCommand('getKeys');
        $this->traceMethodAsCommand('scan');
        $this->traceMethodAsCommand('migrate');
        $this->traceMethodAsCommand('move');
        $this->traceMethodAsCommand('persist');
        $this->traceMethodAsCommand('rename');
        $this->traceMethodAsCommand('object');
        $this->traceMethodAsCommand('randomKey');
        $this->traceMethodAsCommand('renameKey');
        $this->traceMethodAsCommand('renameNx');
        $this->traceMethodAsCommand('type');
        $this->traceMethodAsCommand('sort');
        $this->traceMethodAsCommand('expire');
        $this->traceMethodAsCommand('expireAt');
        $this->traceMethodAsCommand('setTimeout');
        $this->traceMethodAsCommand('pexpire');
        $this->traceMethodAsCommand('pexpireAt');
        $this->traceMethodAsCommand('ttl');
        $this->traceMethodAsCommand('pttl');

        // Hash functions
        $this->traceMethodAsCommand('hDel');
        $this->traceMethodAsCommand('hExists');
        $this->traceMethodAsCommand('hGet');
        $this->traceMethodAsCommand('hGetAll');
        $this->traceMethodAsCommand('hIncrBy');
        $this->traceMethodAsCommand('hIncrByFloat');
        $this->traceMethodAsCommand('hKeys');
        $this->traceMethodAsCommand('hLen');
        $this->traceMethodAsCommand('hMGet');
        $this->traceMethodAsCommand('hMSet');
        $this->traceMethodAsCommand('hSet');
        $this->traceMethodAsCommand('hSetNx');
        $this->traceMethodAsCommand('hVals');
        $this->traceMethodAsCommand('hScan');
        $this->traceMethodAsCommand('hStrLen');

        // Lists
        $this->traceMethodAsCommand('blPop');
        $this->traceMethodAsCommand('brPop');
        $this->traceMethodAsCommand('bRPopLPush');
        $this->traceMethodAsCommand('lGet');
        $this->traceMethodAsCommand('lGetRange');
        $this->traceMethodAsCommand('lIndex');
        $this->traceMethodAsCommand('lInsert');
        $this->traceMethodAsCommand('listTrim');
        $this->traceMethodAsCommand('lLen');
        $this->traceMethodAsCommand('lPop');
        $this->traceMethodAsCommand('lPush');
        $this->traceMethodAsCommand('lPushx');
        $this->traceMethodAsCommand('lRange');
        $this->traceMethodAsCommand('lRem');
        $this->traceMethodAsCommand('lRemove');
        $this->traceMethodAsCommand('lSet');
        $this->traceMethodAsCommand('lSize');
        $this->traceMethodAsCommand('lTrim');
        $this->traceMethodAsCommand('rPop');
        $this->traceMethodAsCommand('rPopLPush');
        $this->traceMethodAsCommand('rPush');
        $this->traceMethodAsCommand('rPushX');

        // Sets
        $this->traceMethodAsCommand('sAdd');
        $this->traceMethodAsCommand('sCard');
        $this->traceMethodAsCommand('sContains');
        $this->traceMethodAsCommand('sDiff');
        $this->traceMethodAsCommand('sDiffStore');
        $this->traceMethodAsCommand('sGetMembers');
        $this->traceMethodAsCommand('sInter');
        $this->traceMethodAsCommand('sInterStore');
        $this->traceMethodAsCommand('sIsMember');
        $this->traceMethodAsCommand('sMembers');
        $this->traceMethodAsCommand('sMove');
        $this->traceMethodAsCommand('sPop');
        $this->traceMethodAsCommand('sRandMember');
        $this->traceMethodAsCommand('sRem');
        $this->traceMethodAsCommand('sRemove');
        $this->traceMethodAsCommand('sScan');
        $this->traceMethodAsCommand('sSize');
        $this->traceMethodAsCommand('sUnion');
        $this->traceMethodAsCommand('sUnionStore');

        // Sorted Sets
        $this->traceMethodAsCommand('zAdd');
        $this->traceMethodAsCommand('zCard');
        $this->traceMethodAsCommand('zSize');
        $this->traceMethodAsCommand('zCount');
        $this->traceMethodAsCommand('zIncrBy');
        $this->traceMethodAsCommand('zInter');
        $this->traceMethodAsCommand('zPopMax');
        $this->traceMethodAsCommand('zPopMin');
        $this->traceMethodAsCommand('zRange');
        $this->traceMethodAsCommand('zRangeByScore');
        $this->traceMethodAsCommand('zRevRangeByScore');
        $this->traceMethodAsCommand('zRangeByLex');
        $this->traceMethodAsCommand('zRank');
        $this->traceMethodAsCommand('zRevRank');
        $this->traceMethodAsCommand('zRem');
        $this->traceMethodAsCommand('zRemove');
        $this->traceMethodAsCommand('zDelete');
        $this->traceMethodAsCommand('zRemRangeByRank');
        $this->traceMethodAsCommand('zDeleteRangeByRank');
        $this->traceMethodAsCommand('zRemRangeByScore');
        $this->traceMethodAsCommand('zDeleteRangeByScore');
        $this->traceMethodAsCommand('zRemoveRangeByScore');
        $this->traceMethodAsCommand('zRevRange');
        $this->traceMethodAsCommand('zScore');
        $this->traceMethodAsCommand('zUnion');
        $this->traceMethodAsCommand('zunionstore');
        $this->traceMethodAsCommand('zScan');

        // Publish: we only trace publish because subscribe is blocking and it will have to be manually traced
        // as in long running processes.
        $this->traceMethodAsCommand('publish');

        // Transactions: this should be improved to have 1 root span per transaction (see APMPHP-362).
        $this->traceMethodAsCommand('multi');
        $this->traceMethodAsCommand('exec');

        // Raw command
        $this->traceMethodAsCommand('rawCommand');

        // Scripting
        $this->traceMethodAsCommand('eval');
        $this->traceMethodAsCommand('evalSha');
        $this->traceMethodAsCommand('script');
        $this->traceMethodAsCommand('getLastError');
        $this->traceMethodAsCommand('clearLastError');
        $this->traceMethodAsCommand('_unserialize');
        $this->traceMethodAsCommand('_serialize');

        // Introspection
        $this->traceMethodAsCommand('isConnected');
        $this->traceMethodAsCommand('getHost');
        $this->traceMethodAsCommand('getPort');
        $this->traceMethodAsCommand('getDbNum');
        $this->traceMethodAsCommand('getTimeout');
        $this->traceMethodAsCommand('getReadTimeout');

        // Geocoding
        $this->traceMethodAsCommand('geoAdd');
        $this->traceMethodAsCommand('geoHash');
        $this->traceMethodAsCommand('geoPos');
        $this->traceMethodAsCommand('geoDist');
        $this->traceMethodAsCommand('geoRadius');
        $this->traceMethodAsCommand('geoRadiusByMember');

        // Streams
        $this->traceMethodAsCommand('xAck');
        $this->traceMethodAsCommand('xAdd');
        $this->traceMethodAsCommand('xClaim');
        $this->traceMethodAsCommand('xDel');
        $this->traceMethodAsCommand('xGroup');
        $this->traceMethodAsCommand('xInfo');
        $this->traceMethodAsCommand('xLen');
        $this->traceMethodAsCommand('xPending');
        $this->traceMethodAsCommand('xRange');
        $this->traceMethodAsCommand('xRead');
        $this->traceMethodAsCommand('xReadGroup');
        $this->traceMethodAsCommand('xRevRange');
        $this->traceMethodAsCommand('xTrim');

        return SandboxedIntegration::LOADED;
    }

    public static function enrichSpan(SpanData $span, $method = null)
    {
        $span->service = 'phpredis';
        $span->type = Type::REDIS;
        if (null !== $method) {
            // method names for internal functions are lowered so we need to explitly set them if we want to have the
            // proper case.
            $span->name = $span->resource = "Redis.$method";
        }
    }

    public function traceMethodNoArgs($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            PHPRedisSandboxedIntegration::enrichSpan($span, $method);
        });
    }

    public function traceMethodAsCommand($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            PHPRedisSandboxedIntegration::enrichSpan($span, $method);
            $normalizedArgs = PHPRedisSandboxedIntegration::normalizeArgs($args);
            // Obfuscable methods: see https://github.com/DataDog/datadog-agent/blob/master/pkg/trace/obfuscate/redis.go
            $span->meta[Tag::REDIS_RAW_COMMAND]
                = empty($normalizedArgs) ? $method : ($method . ' ' . $normalizedArgs);
        });
    }

    /**
     * Based on logic from python tracer:
     * https://github.com/DataDog/dd-trace-py/blob/0d7e7cb38216acb0c8b29f0ae1318d25bc160123/ddtrace/contrib/redis/util.py#L25
     *
     * @param array $args
     * @return string
     */
    public static function normalizeArgs($args)
    {
        $rawCommandParts = [];

        $totalArgsLength = 0;
        foreach ($args as $arg) {
            if ($totalArgsLength > self::CMD_MAX_LEN) {
                break;
            }

            $partValue = null;

            if (\is_string($arg)) {
                $partValue = $arg;
            } elseif (\is_numeric($arg)) {
                $partValue = (string)$arg;
            } elseif (\is_null($arg)) {
                $partValue = 'null';
            } elseif (\is_bool($arg)) {
                $partValue = $args ? 'true' : false;
            } elseif (\is_array($arg)) {
                if (empty($arg)) {
                    continue;
                }
                // This is best effort. If we misinterpret the array as associative the worst that can happen is that we
                // generate '0 a 2 b' instead of 'a b'. We accept this in order to keep things as simple as possible.
                $expectedNextIndex = 0;
                foreach ($arg as $key => $val) {
                    if ($key !== $expectedNextIndex++) {
                        // In this case is associative
                        $rawCommandParts[] = $key;
                    }
                    $rawCommandParts[] = self::normalizeArgs([ $val ]);
                }
                continue;
            } else {
                $rawCommandParts[] = self::VALUE_PLACEHOLDER;
                continue;
            }

            $len = strlen($partValue);
            if ($len > self::VALUE_MAX_LEN) {
                $partValue = substr($partValue, 0, self::VALUE_MAX_LEN) . self::VALUE_TOO_LONG_MARK;
            }
            if ($totalArgsLength + $len > self::CMD_MAX_LEN) {
                $partValue = substr($partValue, 0, self::CMD_MAX_LEN) . self::VALUE_TOO_LONG_MARK;
            }

            $rawCommandParts[] = $partValue;
            $totalArgsLength += strlen($partValue);
        }

        return \implode(' ', $rawCommandParts);
    }
}
