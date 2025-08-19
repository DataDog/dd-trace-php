<?php

namespace DDTrace\Integrations\Memcached;

use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Obfuscation;

/**
 * Tracing of the Memcached library.
 *
 * Not currently dealt with: getDelayed(Multi) and fetch(All). Could be added;
 * would probably want to wrap the callback to getDelayed(Multi) if it is
 * present as well.
 *
 * Also not wrapped: callables passed to get()/getByKey()
 *
 * setMulti and deleteMulti don't generate out.host and out.port because it
 * might be different for each key. setMultiByKey does, since you're pinning a
 * specific server.
 */
class MemcachedIntegration extends Integration
{
    const NAME = 'memcached';

    public static function init(): int
    {
        if (!extension_loaded('memcached')) {
            return Integration::NOT_AVAILABLE;
        }

        self::traceCommand('add');
        self::traceCommandByKey('addByKey');

        self::traceCommand('append');
        self::traceCommandByKey('appendByKey');

        self::traceCommand('decrement');
        self::traceCommandByKey('decrementByKey');

        self::traceCommand('delete');
        self::traceMulti('deleteMulti');
        self::traceCommandByKey('deleteByKey');
        self::traceMultiByKey('deleteMultiByKey');

        self::traceCommand('get');
        self::traceMulti('getMulti');
        self::traceCommandByKey('getByKey');
        self::traceMultiByKey('getMultiByKey');

        self::traceCommand('set');
        self::traceMulti('setMulti');
        self::traceCommandByKey('setByKey');
        self::traceMultiByKey('setMultiByKey');

        self::traceCommand('increment');
        self::traceCommandByKey('incrementByKey');

        self::traceCommand('prepend');
        self::traceCommandByKey('prependByKey');

        self::traceCommand('replace');
        self::traceCommandByKey('replaceByKey');

        self::traceCommand('touch');
        self::traceCommandByKey('touchByKey');

        \DDTrace\trace_method('Memcached', 'flush', function (SpanData $span) {
            MemcachedIntegration::setCommonData($span, 'flush');
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            MemcachedIntegration::setServerTags($span, $this);
        });

        \DDTrace\trace_method('Memcached', 'cas', function (SpanData $span, $args) {
            MemcachedIntegration::setCommonData($span, 'cas');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'cas ?';
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            MemcachedIntegration::setServerTags($span, $this);
        });

        \DDTrace\trace_method('Memcached', 'casByKey', function (SpanData $span, $args) {
            MemcachedIntegration::setCommonData($span, 'casByKey');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'casByKey ?';
            $span->meta['memcached.server_key'] = $args[1];
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;

            MemcachedIntegration::setServerTags($span, $this);
        });

        return Integration::LOADED;
    }

    public static function traceCommand($command)
    {
        \DDTrace\trace_method(
            'Memcached',
            $command,
            function (SpanData $span, $args, $retval) use ($command) {
                MemcachedIntegration::setCommonData($span, $command);
                if ($command === 'get') {
                    $span->metrics[Tag::DB_ROW_COUNT] = empty($retval) ? 0 : 1;
                }
                if (!is_array($args[0])) {
                    MemcachedIntegration::setServerTags($span, $this);
                    $span->meta['memcached.query'] = $command . ' ' . MemcachedIntegration::obfuscateIfNeeded($args[0]);
                }
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;

                MemcachedIntegration::markForTraceAnalytics($span, $command);
            }
        );
    }

    public static function traceCommandByKey($command)
    {
        \DDTrace\trace_method(
            'Memcached',
            $command,
            function (SpanData $span, $args, $retval) use ($command) {
                MemcachedIntegration::setCommonData($span, $command);
                if ($command === 'getByKey') {
                    $span->metrics[Tag::DB_ROW_COUNT] = empty($retval) ? 0 : 1;
                }
                if (!is_array($args[0])) {
                    MemcachedIntegration::setServerTags($span, $this);
                    $span->meta['memcached.query'] = $command . ' ' . MemcachedIntegration::obfuscateIfNeeded($args[0]);
                    $span->meta['memcached.server_key'] = $args[0];
                }
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;

                MemcachedIntegration::markForTraceAnalytics($span, $command);
            }
        );
    }

    public static function traceMulti($command)
    {
        \DDTrace\trace_method(
            'Memcached',
            $command,
            function (SpanData $span, $args, $retval) use ($command) {
                MemcachedIntegration::setCommonData($span, $command);
                if ($command === 'getMulti') {
                    $span->metrics[Tag::DB_ROW_COUNT] = isset($retval) ? (is_array($retval) ? count($retval) : 1) : 0;
                }
                MemcachedIntegration::setServerTags($span, $this);
                $span->meta['memcached.query'] = $command . ' ' . MemcachedIntegration::obfuscateIfNeeded($args[0], ',');
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                MemcachedIntegration::markForTraceAnalytics($span, $command);
            }
        );
    }

    public static function traceMultiByKey($command)
    {
        \DDTrace\trace_method(
            'Memcached',
            $command,
            function (SpanData $span, $args, $retval) use ($command) {
                MemcachedIntegration::setCommonData($span, $command);
                if ($command === 'getMultiByKey') {
                    $span->metrics[Tag::DB_ROW_COUNT] = isset($retval) ? (is_array($retval) ? count($retval) : 1) : 0;
                }
                $span->meta['memcached.server_key'] = $args[0];
                MemcachedIntegration::setServerTags($span, $this);
                $query = "$command " . MemcachedIntegration::obfuscateIfNeeded($args[1], ',');
                $span->meta['memcached.query'] = $query;
                $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
                MemcachedIntegration::markForTraceAnalytics($span, $command);
            }
        );
    }

    /**
     * Sets common values shared by many commands.
     *
     * @param SpanData $span
     * @param string $command
     */
    public static function setCommonData(SpanData $span, $command)
    {
        $span->name = "Memcached.$command";
        $span->type = Type::MEMCACHED;
        $span->service = 'memcached';
        Integration::handleInternalSpanServiceName($span, self::NAME);
        $span->resource = $command;
        $span->meta['memcached.command'] = $command;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::NAME;
    }

    /**
     * Add the servers to the span metadata.
     *
     * Do not call `Memcached::getServerByKey()` since it mutates the
     * result code. `Memcached::getServerList()` is more stable
     * because it does not mutate the result code. One side effect to
     * using the more stable API is that it is not possible to identify
     * the specific server used in the original call when there are
     * multiple servers.
     *
     * @param SpanData $span
     * @param \Memcached $memcached
     */
    public static function setServerTags(SpanData $span, \Memcached $memcached)
    {
        $servers = $memcached->getServerList();
        /*
         * There can be a lot of servers in the list, so just take the
         * top one to keep memory overhead low.
         */
        if (isset($servers[0]['host'], $servers[0]['port'])) {
            $span->meta[Tag::TARGET_HOST] = $servers[0]['host'];
            $span->meta[Tag::TARGET_PORT] = $servers[0]['port'];
        }
    }

    /**
     * @param SpanData $span
     * @param string $command
     */
    public static function markForTraceAnalytics(SpanData $span, $command)
    {
        $commandsForAnalytics = [
            'add',
            'addByKey',
            'delete',
            'deleteByKey',
            'get',
            'getByKey',
            'set',
            'setByKey',
        ];

        if (in_array($command, $commandsForAnalytics)) {
            self::addTraceAnalyticsIfEnabled($span);
        }
    }

    /*
     * Return either the obfuscated params or the params themselves, depending on the env var.
     */
    public static function obfuscateIfNeeded($params, $glue = ' ')
    {
        if (dd_trace_env_config("DD_TRACE_MEMCACHED_OBFUSCATION")) {
            return Obfuscation::toObfuscatedString($params, $glue);
        } elseif (is_array($params)) {
            return implode($glue, $params);
        } else {
            return $params;
        }
    }
}
