<?php

namespace DDTrace\Integrations\Redis;

use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\GlobalTracer;
use DDTrace\Util\TryCatchFinally;

const VALUE_PLACEHOLDER = "?";
const VALUE_MAX_LEN = 100;
const VALUE_TOO_LONG_MARK = "...";
const CMD_MAX_LEN = 1000;

class RedisIntegration extends Integration
{
    const NAME = 'redis';

    /**
     * @var array
     */
    private static $connections = [];

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Static method to add instrumentation to the Predis library
     */
    public static function load()
    {
        if (!extension_loaded('redis')) {
            // `curl` extension is not loaded, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        dd_trace('Redis', 'connect', function () {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                RedisIntegration::getInstance(),
                'Redis.connect'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
            $span->setTag(Tag::SERVICE_NAME, 'redis');
            $span->setTag(Tag::RESOURCE_NAME, 'Redis.connect');


            $thrown = null;
            try {
                $result = call_user_func_array([$this, 'connect'], $args);
            } catch (\Exception $e) {
                RedisIntegration::setErrorOnException($span, $e);
                $thrown = $e;
            }
            $scope->close();

            if ($thrown) {
                throw $thrown;
            }

            return $result;
        });

        dd_trace('Redis', 'getSet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getSet', $args);
        });
        dd_trace('Redis', 'getRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getRange', $args);
        });
        dd_trace('Redis', 'getBit', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getBit', $args);
        });
        dd_trace('Redis', 'get', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'get', $args);
        });
        dd_trace('Redis', 'decrBy', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'decrBy', $args);
        });
        dd_trace('Redis', 'decr', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'decr', $args);
        });
        dd_trace('Redis', 'bitOp', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'bitOp', $args);
        });
        dd_trace('Redis', 'bitCount', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'bitCount', $args);
        });
        dd_trace('Redis', 'append', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'append', $args);
        });
        dd_trace('Redis', 'incr', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'incr', $args);
        });
        dd_trace('Redis', 'incrBy', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'incrBy', $args);
        });
        dd_trace('Redis', 'incrByFloat', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'incrByFloat', $args);
        });
        dd_trace('Redis', 'mGet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'mGet', $args);
        });
        dd_trace('Redis', 'getMultiple', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getMultiple', $args);
        });
        dd_trace('Redis', 'mSet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'mSet', $args);
        });
        dd_trace('Redis', 'mSetNX', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'mSetNX', $args);
        });
        dd_trace('Redis', 'set', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'set', $args);
        });
        dd_trace('Redis', 'setBit', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setBit', $args);
        });
        dd_trace('Redis', 'setEx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setEx', $args);
        });
        dd_trace('Redis', 'pSetEx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pSetEx', $args);
        });
        dd_trace('Redis', 'setNx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setNx', $args);
        });
        dd_trace('Redis', 'setRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setRange', $args);
        });
        dd_trace('Redis', 'strLen', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'strLen', $args);
        });
        dd_trace('Redis', 'del', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'del', $args);
        });
        dd_trace('Redis', 'delete', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'delete', $args);
        });
        dd_trace('Redis', 'unlink', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'unlink', $args);
        });
        dd_trace('Redis', 'dump', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'dump', $args);
        });
        dd_trace('Redis', 'exists', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'exists', $args);
        });
        dd_trace('Redis', 'expire', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'expire', $args);
        });
        dd_trace('Redis', 'setTimeout', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setTimeout', $args);
        });
        dd_trace('Redis', 'pexpire', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pexpire', $args);
        });
        dd_trace('Redis', 'expireAt', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'expireAt', $args);
        });
        dd_trace('Redis', 'pexpireAt', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pexpireAt', $args);
        });
        dd_trace('Redis', 'keys', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'keys', $args);
        });
        dd_trace('Redis', 'getKeys', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getKeys', $args);
        });
        dd_trace('Redis', 'scan', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'scan', $args);
        });
        dd_trace('Redis', 'migrate', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'migrate', $args);
        });
        dd_trace('Redis', 'move', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'move', $args);
        });
        dd_trace('Redis', 'object', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'object', $args);
        });
        dd_trace('Redis', 'persist', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'persist', $args);
        });
        dd_trace('Redis', 'randomKey', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'randomKey', $args);
        });
        dd_trace('Redis', 'rename', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rename', $args);
        });
        dd_trace('Redis', 'renameKey', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'renameKey', $args);
        });
        dd_trace('Redis', 'renameNx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'renameNx', $args);
        });
        dd_trace('Redis', 'type', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'type', $args);
        });
        dd_trace('Redis', 'sort', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sort', $args);
        });
        dd_trace('Redis', 'ttl', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'ttl', $args);
        });
        dd_trace('Redis', 'pttl', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pttl', $args);
        });
        dd_trace('Redis', 'restore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'restore', $args);
        });
        dd_trace('Redis', 'bgRewriteAOF', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'bgRewriteAOF', $args);
        });
        dd_trace('Redis', 'bgSave', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'bgSave', $args);
        });
        dd_trace('Redis', 'config', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'config', $args);
        });
        dd_trace('Redis', 'dbSize', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'dbSize', $args);
        });
        dd_trace('Redis', 'flushAll', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'flushAll', $args);
        });
        dd_trace('Redis', 'flushDb', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'flushDb', $args);
        });
        dd_trace('Redis', 'info', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'info', $args);
        });
        dd_trace('Redis', 'lastSave', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lastSave', $args);
        });
        dd_trace('Redis', 'resetStat', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'resetStat', $args);
        });
        dd_trace('Redis', 'save', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'save', $args);
        });
        dd_trace('Redis', 'slaveOf', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'slaveOf', $args);
        });
        dd_trace('Redis', 'time', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'time', $args);
        });
        dd_trace('Redis', 'slowLog', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'slowLog', $args);
        });
        dd_trace('Redis', 'hDel', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hDel', $args);
        });
        dd_trace('Redis', 'hExists', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hExists', $args);
        });
        dd_trace('Redis', 'hGet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hGet', $args);
        });
        dd_trace('Redis', 'hGetAll', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hGetAll', $args);
        });
        dd_trace('Redis', 'hIncrBy', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hIncrBy', $args);
        });
        dd_trace('Redis', 'hIncrByFloat', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hIncrByFloat', $args);
        });
        dd_trace('Redis', 'hKeys', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hKeys', $args);
        });
        dd_trace('Redis', 'hLen', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hLen', $args);
        });
        dd_trace('Redis', 'hMGet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hMGet', $args);
        });
        dd_trace('Redis', 'hMSet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hMSet', $args);
        });
        dd_trace('Redis', 'hSet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hSet', $args);
        });
        dd_trace('Redis', 'hSetNx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hSetNx', $args);
        });
        dd_trace('Redis', 'hVals', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hVals', $args);
        });
        dd_trace('Redis', 'hScan', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hScan', $args);
        });
        dd_trace('Redis', 'hStrLen', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'hStrLen', $args);
        });
        dd_trace('Redis', 'blPop', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'blPop', $args);
        });
        dd_trace('Redis', 'brPop', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'brPop', $args);
        });
        dd_trace('Redis', 'bRPopLPush', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'bRPopLPush', $args);
        });
        dd_trace('Redis', 'lIndex', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lIndex', $args);
        });
        dd_trace('Redis', 'lGet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lGet', $args);
        });
        dd_trace('Redis', 'lInsert', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lInsert', $args);
        });
        dd_trace('Redis', 'lLen', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lLen', $args);
        });
        dd_trace('Redis', 'lSize', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lSize', $args);
        });
        dd_trace('Redis', 'lPop', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lPop', $args);
        });
        dd_trace('Redis', 'lPush', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lPush', $args);
        });
        dd_trace('Redis', 'lPushx', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lPushx', $args);
        });
        dd_trace('Redis', 'lRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lRange', $args);
        });
        dd_trace('Redis', 'lGetRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lGetRange', $args);
        });
        dd_trace('Redis', 'lRem', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lRem', $args);
        });
        dd_trace('Redis', 'lRemove', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lRemove', $args);
        });
        dd_trace('Redis', 'lSet', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lSet', $args);
        });
        dd_trace('Redis', 'lTrim', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'lTrim', $args);
        });
        dd_trace('Redis', 'listTrim', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'listTrim', $args);
        });
        dd_trace('Redis', 'rPop', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rPop', $args);
        });
        dd_trace('Redis', 'rPopLPush', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rPopLPush', $args);
        });
        dd_trace('Redis', 'rPush', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rPush', $args);
        });
        dd_trace('Redis', 'rPushX', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rPushX', $args);
        });
        dd_trace('Redis', 'sAdd', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sAdd', $args);
        });
        dd_trace('Redis', 'sCard', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sCard', $args);
        });
        dd_trace('Redis', 'sSize', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sSize', $args);
        });
        dd_trace('Redis', 'sDiff', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sDiff', $args);
        });
        dd_trace('Redis', 'sDiffStore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sDiffStore', $args);
        });
        dd_trace('Redis', 'sInter', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sInter', $args);
        });
        dd_trace('Redis', 'sInterStore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sInterStore', $args);
        });
        dd_trace('Redis', 'sIsMember', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sIsMember', $args);
        });
        dd_trace('Redis', 'sContains', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sContains', $args);
        });
        dd_trace('Redis', 'sMembers', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sMembers', $args);
        });
        dd_trace('Redis', 'sGetMembers', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sGetMembers', $args);
        });
        dd_trace('Redis', 'sMove', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sMove', $args);
        });
        dd_trace('Redis', 'sPop', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sPop', $args);
        });
        dd_trace('Redis', 'sRandMember', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sRandMember', $args);
        });
        dd_trace('Redis', 'sRem', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sRem', $args);
        });
        dd_trace('Redis', 'sRemove', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sRemove', $args);
        });
        dd_trace('Redis', 'sUnion', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sUnion', $args);
        });
        dd_trace('Redis', 'sUnionStore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sUnionStore', $args);
        });
        dd_trace('Redis', 'sScan', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'sScan', $args);
        });
        dd_trace('Redis', 'zAdd', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zAdd', $args);
        });
        dd_trace('Redis', 'zCard', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zCard', $args);
        });
        dd_trace('Redis', 'zSize', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zSize', $args);
        });
        dd_trace('Redis', 'zCount', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zCount', $args);
        });
        dd_trace('Redis', 'zIncrBy', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zIncrBy', $args);
        });
        dd_trace('Redis', 'zInter', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zInter', $args);
        });
        dd_trace('Redis', 'zRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRange', $args);
        });
        dd_trace('Redis', 'zRangeByScore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRangeByScore', $args);
        });
        dd_trace('Redis', 'zRevRangeByScore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRevRangeByScore', $args);
        });
        dd_trace('Redis', 'zRangeByLex', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRangeByLex', $args);
        });
        dd_trace('Redis', 'zRank', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRank', $args);
        });
        dd_trace('Redis', 'zRevRank', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRevRank', $args);
        });
        dd_trace('Redis', 'zRem', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRem', $args);
        });
        dd_trace('Redis', 'zDelete', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zDelete', $args);
        });
        dd_trace('Redis', 'zRemRangeByRank', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRemRangeByRank', $args);
        });
        dd_trace('Redis', 'zDeleteRangeByRank', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zDeleteRangeByRank', $args);
        });
        dd_trace('Redis', 'zRemRangeByScore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRemRangeByScore', $args);
        });
        dd_trace('Redis', 'zDeleteRangeByScore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zDeleteRangeByScore', $args);
        });
        dd_trace('Redis', 'zRevRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zRevRange', $args);
        });
        dd_trace('Redis', 'zScore', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zScore', $args);
        });
        dd_trace('Redis', 'zUnion', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zUnion', $args);
        });
        dd_trace('Redis', 'zScan', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'zScan', $args);
        });
        dd_trace('Redis', 'multi', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'multi', $args);
        });
        dd_trace('Redis', 'exec', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'exec', $args);
        });
        dd_trace('Redis', 'discard', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'discard', $args);
        });
        dd_trace('Redis', 'watch', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'watch', $args);
        });
        dd_trace('Redis', 'unwatch', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'unwatch', $args);
        });
        dd_trace('Redis', 'rawCommand', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'rawCommand', $args);
        });
        dd_trace('Redis', 'setOption', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'setOption', $args);
        });
        dd_trace('Redis', 'getOption', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getOption', $args);
        });
        dd_trace('Redis', 'ping', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'ping', $args);
        });
        dd_trace('Redis', 'echo', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'echo', $args);
        });
        dd_trace('Redis', 'close', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'close', $args);
        });
        dd_trace('Redis', 'select', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'select', $args);
        });
        dd_trace('Redis', 'eval', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'eval', $args);
        });
        dd_trace('Redis', 'evalSha', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'evalSha', $args);
        });
        dd_trace('Redis', 'script', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'script', $args);
        });
        dd_trace('Redis', 'getLastError', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'getLastError', $args);
        });
        dd_trace('Redis', 'clearLastError', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'clearLastError', $args);
        });
        dd_trace('Redis', 'geoAdd', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'geoAdd', $args);
        });
        dd_trace('Redis', 'geoHash', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'geoHash', $args);
        });
        dd_trace('Redis', 'geoPos', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'geoPos', $args);
        });
        dd_trace('Redis', 'GeoDist', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'GeoDist', $args);
        });
        dd_trace('Redis', 'geoRadius', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'geoRadius', $args);
        });
        dd_trace('Redis', 'geoRadiusByMember', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'geoRadiusByMember', $args);
        });
        dd_trace('Redis', 'xAck', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xAck', $args);
        });
        dd_trace('Redis', 'xAdd', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xAdd', $args);
        });
        dd_trace('Redis', 'xClaim', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xClaim', $args);
        });
        dd_trace('Redis', 'xDel', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xDel', $args);
        });
        dd_trace('Redis', 'xGroup', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xGroup', $args);
        });
        dd_trace('Redis', 'xInfo', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xInfo', $args);
        });
        dd_trace('Redis', 'xLen', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xLen', $args);
        });
        dd_trace('Redis', 'xPending', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xPending', $args);
        });
        dd_trace('Redis', 'xRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xRange', $args);
        });
        dd_trace('Redis', 'xRead', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xRead', $args);
        });
        dd_trace('Redis', 'xReadGroup', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xReadGroup', $args);
        });
        dd_trace('Redis', 'xRevRange', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xRevRange', $args);
        });
        dd_trace('Redis', 'xTrim', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'xTrim', $args);
        });
        dd_trace('Redis', 'pSubscribe', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pSubscribe', $args);
        });
        dd_trace('Redis', 'publish', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'publish', $args);
        });
        dd_trace('Redis', 'subscribe', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'subscribe', $args);
        });
        dd_trace('Redis', 'pubSub', function () {
            $args = func_get_args();
            return RedisIntegration::traceCommand($this, 'pubSub', $args);
        });

        return Integration::LOADED;
    }

    public static function traceCommand($redis, $command, $args)
    {
        $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
            RedisIntegration::getInstance(),
            "Redis.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::CACHE);
        $span->setTag(Tag::SERVICE_NAME, 'redis');
        $span->setTag('redis.command', $command);

        self::setServerTags($span, $redis);

        $span->setTag(Tag::RESOURCE_NAME, $command);

        return TryCatchFinally::executePublicMethod($scope, $redis, $command, $args);
    }

    private static function setServerTags($span, $redis)
    {
        $span->setTag(Tag::TARGET_HOST, $redis->getHost());
        $span->setTag(Tag::TARGET_PORT, $redis->getPort());
    }
}
