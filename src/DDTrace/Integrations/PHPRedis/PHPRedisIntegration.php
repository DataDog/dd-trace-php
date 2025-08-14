<?php

namespace DDTrace\Integrations\PHPRedis;

use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;

class PHPRedisIntegration extends Integration
{
    const NAME = 'phpredis';
    const SYSTEM = 'redis';

    const NOT_SET = '__DD_NOT_SET__';
    const CMD_MAX_LEN = 1000;
    const VALUE_TOO_LONG_MARK = '...';
    const VALUE_MAX_LEN = 100;
    const VALUE_PLACEHOLDER = "?";

    const DEFAULT_HOST = '127.0.0.1';
    const DEFAULT_PORT = 6379;

    const KEY_HOST = 'host';
    const KEY_CLUSTER_NAME = 'cluster_name';
    const KEY_FIRST_HOST = 'first_host';
    const KEY_FIRST_HOST_OR_UDS = 'first_host_or_uds';

    // These should go to Tag.php, but until we discuss on how to handle the cluster name attribute and semathics, it
    // stays here
    const INTERNAL_ONLY_TAG_CLUSTER_NAME = '_dd.cluster.name';
    const INTERNAL_ONLY_TAG_FIRST_HOST = '_dd.first.configured.host';

    public static function init(): int
    {
        $traceConnectOpen = function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            $hostOrUDS = (isset($args[0]) && \is_string($args[0])) ? $args[0] : PHPRedisIntegration::DEFAULT_HOST;
            $span->meta[Tag::TARGET_HOST] = $hostOrUDS;
            $span->meta[Tag::TARGET_PORT] = isset($args[1]) && \is_numeric($args[1]) ?
                $args[1] :
                PHPRedisIntegration::DEFAULT_PORT;
            $span->meta[Tag::SPAN_KIND] = 'client';

            // While we would have access to Redis::getHost() from the instance to retrieve it later, we compute the
            // service name here and store in the instance for later because of the following reasons:
            //   - we would do over and over the same operation, involving a regex, for each method invocation.
            //   - in case of connection error, the Redis::host value is not set and we would not have access to it
            //     during callbacks, meaning that we would have to use two different ways to extract the name: args or
            //     Redis::getHost() depending on when we are interested in such information.
            ObjectKVStore::put($this, PHPRedisIntegration::KEY_HOST, $hostOrUDS);

            PHPRedisIntegration::enrichSpan($span, $this, 'Redis');
        };
        \DDTrace\trace_method('Redis', 'connect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'pconnect', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'open', $traceConnectOpen);
        \DDTrace\trace_method('Redis', 'popen', $traceConnectOpen);

        $traceNewCluster = function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            if (isset($args[1]) && \is_array($args[1]) && !empty($args[1])) {
                $firstHostOrUDS = $args[1][0];
            } else {
                $seeds = \ini_get('redis.clusters.seeds');
                if (!empty($seeds)) {
                    $clusters = [];
                    parse_str($seeds, $clusters);
                    if (array_key_exists($args[0], $clusters) && !empty($clusters[$args[0]])) {
                        $firstHostOrUDS = $clusters[$args[0]][0];
                    }
                }
            }
            if (empty($firstHostOrUDS)) {
                $firstHostOrUDS = PHPRedisIntegration::DEFAULT_HOST;
            }

            $configuredClusterName = isset($args[0]) && \is_string($args[0]) ? $args[0] : null;
            ObjectKVStore::put($this, PHPRedisIntegration::KEY_CLUSTER_NAME, $configuredClusterName);

            $url = parse_url($firstHostOrUDS);
            $firstConfiguredHost = is_array($url) && isset($url["host"]) ?
                $url["host"] :
                PHPRedisIntegration::DEFAULT_HOST;
            $span->meta[Tag::TARGET_HOST] = $firstConfiguredHost;
            $span->meta[Tag::TARGET_PORT] = is_array($url) && isset($url["port"]) ?
                $url["port"] :
                PHPRedisIntegration::DEFAULT_PORT;
            ObjectKVStore::put($this, PHPRedisIntegration::KEY_FIRST_HOST, $firstConfiguredHost);
            ObjectKVStore::put($this, PHPRedisIntegration::KEY_FIRST_HOST_OR_UDS, $firstHostOrUDS);

            PHPRedisIntegration::enrichSpan($span, $this, 'RedisCluster');
        };
        \DDTrace\trace_method('RedisCluster', '__construct', $traceNewCluster);

        self::traceMethodNoArgs('close');
        self::traceMethodNoArgs('auth');
        self::traceMethodNoArgs('ping');
        self::traceMethodNoArgs('echo');
        self::traceMethodNoArgs('bgRewriteAOF');
        self::traceMethodNoArgs('bgSave');
        self::traceMethodNoArgs('flushAll');
        self::traceMethodNoArgs('flushDb');
        self::traceMethodNoArgs('save');
        // We do not trace arguments of restore as they are binary
        self::traceMethodNoArgs('restore');

        \DDTrace\trace_method('Redis', 'select', function (SpanData $span, $args) {
            Integration::handleOrphan($span);

            PHPRedisIntegration::enrichSpan($span, $this, 'Redis');
            if (isset($args[0]) && \is_numeric($args[0])) {
                $span->meta['db.index'] = $args[0];
            }

            $host = ObjectKVStore::get($this, PHPRedisIntegration::KEY_HOST);
            $span->meta[Tag::TARGET_HOST] = $host;
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });

        self::traceMethodAsCommand('swapdb');

        self::traceMethodAsCommand('append');
        self::traceMethodAsCommand('decr');
        self::traceMethodAsCommand('decrBy');
        self::traceMethodAsCommand('get');
        self::traceMethodAsCommand('getBit');
        self::traceMethodAsCommand('getRange');
        self::traceMethodAsCommand('getSet');
        self::traceMethodAsCommand('incr');
        self::traceMethodAsCommand('incrBy');
        self::traceMethodAsCommand('incrByFloat');
        self::traceMethodAsCommand('mGet');
        self::traceMethodAsCommand('getMultiple');
        self::traceMethodAsCommand('mSet');
        self::traceMethodAsCommand('mSetNx');
        self::traceMethodAsCommand('set');
        self::traceMethodAsCommand('setBit');
        self::traceMethodAsCommand('setEx');
        self::traceMethodAsCommand('pSetEx');
        self::traceMethodAsCommand('setNx');
        self::traceMethodAsCommand('setRange');
        self::traceMethodAsCommand('strLen');

        self::traceMethodAsCommand('del');
        self::traceMethodAsCommand('delete');
        self::traceMethodAsCommand('dump');
        self::traceMethodAsCommand('exists');
        self::traceMethodAsCommand('keys');
        self::traceMethodAsCommand('getKeys');
        self::traceMethodAsCommand('scan');
        self::traceMethodAsCommand('migrate');
        self::traceMethodAsCommand('move');
        self::traceMethodAsCommand('persist');
        self::traceMethodAsCommand('rename');
        self::traceMethodAsCommand('object');
        self::traceMethodAsCommand('randomKey');
        self::traceMethodAsCommand('renameKey');
        self::traceMethodAsCommand('renameNx');
        self::traceMethodAsCommand('type');
        self::traceMethodAsCommand('sort');
        self::traceMethodAsCommand('expire');
        self::traceMethodAsCommand('expireAt');
        self::traceMethodAsCommand('setTimeout');
        self::traceMethodAsCommand('pexpire');
        self::traceMethodAsCommand('pexpireAt');
        self::traceMethodAsCommand('ttl');
        self::traceMethodAsCommand('pttl');

        // Hash functions
        self::traceMethodAsCommand('hDel');
        self::traceMethodAsCommand('hExists');
        self::traceMethodAsCommand('hGet');
        self::traceMethodAsCommand('hGetAll');
        self::traceMethodAsCommand('hIncrBy');
        self::traceMethodAsCommand('hIncrByFloat');
        self::traceMethodAsCommand('hKeys');
        self::traceMethodAsCommand('hLen');
        self::traceMethodAsCommand('hMGet');
        self::traceMethodAsCommand('hMSet');
        self::traceMethodAsCommand('hSet');
        self::traceMethodAsCommand('hSetNx');
        self::traceMethodAsCommand('hVals');
        self::traceMethodAsCommand('hScan');
        self::traceMethodAsCommand('hStrLen');

        // Lists
        self::traceMethodAsCommand('blPop');
        self::traceMethodAsCommand('brPop');
        self::traceMethodAsCommand('bRPopLPush');
        self::traceMethodAsCommand('lGet');
        self::traceMethodAsCommand('lGetRange');
        self::traceMethodAsCommand('lIndex');
        self::traceMethodAsCommand('lInsert');
        self::traceMethodAsCommand('listTrim');
        self::traceMethodAsCommand('lLen');
        self::traceMethodAsCommand('lPop');
        self::traceMethodAsCommand('lPush');
        self::traceMethodAsCommand('lPushx');
        self::traceMethodAsCommand('lRange');
        self::traceMethodAsCommand('lRem');
        self::traceMethodAsCommand('lRemove');
        self::traceMethodAsCommand('lSet');
        self::traceMethodAsCommand('lSize');
        self::traceMethodAsCommand('lTrim');
        self::traceMethodAsCommand('rPop');
        self::traceMethodAsCommand('rPopLPush');
        self::traceMethodAsCommand('rPush');
        self::traceMethodAsCommand('rPushX');

        // Sets
        self::traceMethodAsCommand('sAdd');
        self::traceMethodAsCommand('sCard');
        self::traceMethodAsCommand('sContains');
        self::traceMethodAsCommand('sDiff');
        self::traceMethodAsCommand('sDiffStore');
        self::traceMethodAsCommand('sGetMembers');
        self::traceMethodAsCommand('sInter');
        self::traceMethodAsCommand('sInterStore');
        self::traceMethodAsCommand('sIsMember');
        self::traceMethodAsCommand('sMembers');
        self::traceMethodAsCommand('sMove');
        self::traceMethodAsCommand('sPop');
        self::traceMethodAsCommand('sRandMember');
        self::traceMethodAsCommand('sRem');
        self::traceMethodAsCommand('sRemove');
        self::traceMethodAsCommand('sScan');
        self::traceMethodAsCommand('sSize');
        self::traceMethodAsCommand('sUnion');
        self::traceMethodAsCommand('sUnionStore');

        // Sorted Sets
        self::traceMethodAsCommand('zAdd');
        self::traceMethodAsCommand('zCard');
        self::traceMethodAsCommand('zSize');
        self::traceMethodAsCommand('zCount');
        self::traceMethodAsCommand('zIncrBy');
        self::traceMethodAsCommand('zInter');
        self::traceMethodAsCommand('zInterstore');
        self::traceMethodAsCommand('zPopMax');
        self::traceMethodAsCommand('zPopMin');
        self::traceMethodAsCommand('zRange');
        self::traceMethodAsCommand('zRangeByScore');
        self::traceMethodAsCommand('zRevRangeByScore');
        self::traceMethodAsCommand('zRangeByLex');
        self::traceMethodAsCommand('zRank');
        self::traceMethodAsCommand('zRevRank');
        self::traceMethodAsCommand('zRem');
        self::traceMethodAsCommand('zRemove');
        self::traceMethodAsCommand('zDelete');
        self::traceMethodAsCommand('zRemRangeByRank');
        self::traceMethodAsCommand('zDeleteRangeByRank');
        self::traceMethodAsCommand('zRemRangeByScore');
        self::traceMethodAsCommand('zDeleteRangeByScore');
        self::traceMethodAsCommand('zRemoveRangeByScore');
        self::traceMethodAsCommand('zRevRange');
        self::traceMethodAsCommand('zScore');
        self::traceMethodAsCommand('zUnion');
        self::traceMethodAsCommand('zunionstore');
        self::traceMethodAsCommand('zScan');

        // Publish: we only trace publish because subscribe is blocking and it will have to be manually traced
        // as in long running processes.
        self::traceMethodAsCommand('publish');

        // Transactions: this should be improved to have 1 root span per transaction (see APMPHP-362).
        self::traceMethodAsCommand('multi');
        self::traceMethodAsCommand('exec');

        // Raw command
        self::traceMethodAsCommand('rawCommand');

        // Scripting
        self::traceMethodAsCommand('eval');
        self::traceMethodAsCommand('evalSha');
        self::traceMethodAsCommand('script');
        self::traceMethodAsCommand('getLastError');
        self::traceMethodAsCommand('clearLastError');
        self::traceMethodAsCommand('_unserialize');
        self::traceMethodAsCommand('_serialize');

        // Introspection
        self::traceMethodAsCommand('isConnected');
        self::traceMethodAsCommand('getHost');
        self::traceMethodAsCommand('getPort');
        self::traceMethodAsCommand('getDbNum');
        self::traceMethodAsCommand('getTimeout');
        self::traceMethodAsCommand('getReadTimeout');

        // Geocoding
        self::traceMethodAsCommand('geoAdd');
        self::traceMethodAsCommand('geoHash');
        self::traceMethodAsCommand('geoPos');
        self::traceMethodAsCommand('geoDist');
        self::traceMethodAsCommand('geoRadius');
        self::traceMethodAsCommand('geoRadiusByMember');

        // Streams
        self::traceMethodAsCommand('xAck');
        self::traceMethodAsCommand('xAdd');
        self::traceMethodAsCommand('xClaim');
        self::traceMethodAsCommand('xDel');
        self::traceMethodAsCommand('xGroup');
        self::traceMethodAsCommand('xInfo');
        self::traceMethodAsCommand('xLen');
        self::traceMethodAsCommand('xPending');
        self::traceMethodAsCommand('xRange');
        self::traceMethodAsCommand('xRead');
        self::traceMethodAsCommand('xReadGroup');
        self::traceMethodAsCommand('xRevRange');
        self::traceMethodAsCommand('xTrim');

        return Integration::LOADED;
    }

    public static function enrichSpan(SpanData $span, $instance, $class, $method = null)
    {
        if (\dd_trace_env_config("DD_TRACE_REDIS_CLIENT_SPLIT_BY_HOST")) {
            // For PHP 5 compatibility, keep the results of ObjectKVStore::get() extracted as variables
            $clusterName = ObjectKVStore::get($instance, PHPRedisIntegration::KEY_CLUSTER_NAME);
            $firstHostOrUDS = ObjectKVStore::get($instance, PHPRedisIntegration::KEY_FIRST_HOST_OR_UDS);
            $host = ObjectKVStore::get($instance, PHPRedisIntegration::KEY_HOST);

            $serviceNamePrefix = 'redis-';
            if (!empty($clusterName)) {
                $normalizedClusterName = \DDTrace\Util\Normalizer::normalizeHostUdsAsService($clusterName);
                $span->service = $serviceNamePrefix . $normalizedClusterName;
            } elseif (!empty($firstHostOrUDS)) {
                $normalizedHost = \DDTrace\Util\Normalizer::normalizeHostUdsAsService($firstHostOrUDS);
                $span->service = $serviceNamePrefix . $normalizedHost;
            } elseif (!empty($host)) {
                $normalizedHost = \DDTrace\Util\Normalizer::normalizeHostUdsAsService($host);
                $span->service = $serviceNamePrefix . $normalizedHost;
            } else {
                Integration::handleInternalSpanServiceName($span, PHPRedisIntegration::NAME);
            }
        } else {
            Integration::handleInternalSpanServiceName($span, PHPRedisIntegration::NAME);
        }

        $span->type = Type::REDIS;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = PHPRedisIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = PHPRedisIntegration::SYSTEM;
        if (null !== $method) {
            // method names for internal functions are lowered so we need to explitly set them if we want to have the
            // proper case.
            $span->name = $span->resource = "$class.$method";
        }
    }

    public static function traceMethodNoArgs($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            Integration::handleOrphan($span);

            PHPRedisIntegration::enrichSpan($span, $this, 'Redis', $method);

            $host = ObjectKVStore::get($this, PHPRedisIntegration::KEY_HOST);
            $span->meta[Tag::TARGET_HOST] = $host;
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });
        \DDTrace\trace_method('RedisCluster', $method, function (SpanData $span, $args) use ($method) {
            Integration::handleOrphan($span);

            PHPRedisIntegration::enrichSpan($span, $this, 'RedisCluster', $method);
            if ($clusterName = ObjectKVStore::get($this, PHPRedisIntegration::KEY_CLUSTER_NAME)) {
                $span->meta[PHPRedisIntegration::INTERNAL_ONLY_TAG_CLUSTER_NAME] = $clusterName;
            } elseif ($firstHost = ObjectKVStore::get($this, PHPRedisIntegration::KEY_FIRST_HOST)) {
                $span->meta[PHPRedisIntegration::INTERNAL_ONLY_TAG_FIRST_HOST] = $firstHost;
            }
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });
    }

    public static function traceMethodAsCommand($method)
    {
        \DDTrace\trace_method('Redis', $method, function (SpanData $span, $args) use ($method) {
            Integration::handleOrphan($span);

            PHPRedisIntegration::enrichSpan($span, $this, 'Redis', $method);
            $normalizedArgs = PHPRedisIntegration::normalizeArgs($args);
            // Obfuscable methods: see https://github.com/DataDog/datadog-agent/blob/master/pkg/trace/obfuscate/redis.go
            $span->meta[Tag::REDIS_RAW_COMMAND]
                = empty($normalizedArgs) ? $method : ($method . ' ' . $normalizedArgs);

            $host = ObjectKVStore::get($this, PHPRedisIntegration::KEY_HOST);
            $span->meta[Tag::TARGET_HOST] = $host;
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
        });
        \DDTrace\trace_method('RedisCluster', $method, function (SpanData $span, $args) use ($method) {
            Integration::handleOrphan($span);

            PHPRedisIntegration::enrichSpan($span, $this, 'RedisCluster', $method);
            $normalizedArgs = PHPRedisIntegration::normalizeArgs($args);
            // Obfuscable methods: see https://github.com/DataDog/datadog-agent/blob/master/pkg/trace/obfuscate/redis.go
            $span->meta[Tag::REDIS_RAW_COMMAND]
                = empty($normalizedArgs) ? $method : ($method . ' ' . $normalizedArgs);
            if ($clusterName = ObjectKVStore::get($this, PHPRedisIntegration::KEY_CLUSTER_NAME)) {
                $span->meta[PHPRedisIntegration::INTERNAL_ONLY_TAG_CLUSTER_NAME] = $clusterName;
            } elseif ($firstHost = ObjectKVStore::get($this, PHPRedisIntegration::KEY_FIRST_HOST)) {
                $span->meta[PHPRedisIntegration::INTERNAL_ONLY_TAG_FIRST_HOST] = $firstHost;
            }
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
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
                    $rawCommandParts[] = self::normalizeArgs([$val]);
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
