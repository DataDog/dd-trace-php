<?php

namespace DDTrace\Integrations\Memcache;

use DDTrace\Integrations\DatabaseIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Obfuscation;
use DDTrace\Util\ObjectKVStore;

/**
 * Tracing of the Memcache library.
 */
class MemcacheIntegration extends Integration
{
    const NAME = 'memcache';

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

    public function init(): int
    {
        if (!extension_loaded('memcache')) {
            return Integration::NOT_AVAILABLE;
        }
        $integration = $this;

        $this->traceCommand('add');
        $this->traceCommand('append');
        $this->traceCommand('decrement');
        $this->traceCommand('delete');
        $this->traceCommand('get');
        $this->traceCommand('set');
        $this->traceCommand('increment');
        $this->traceCommand('prepend');
        $this->traceCommand('replace');

        \DDTrace\trace_method('Memcache', 'flush', function (SpanData $span) use ($integration) {
            $integration->setCommonData($span, 'flush');
        });
        \DDTrace\trace_function('memcache_flush', function (SpanData $span) use ($integration) {
            $integration->setCommonData($span, 'flush');
        });

        $memcache_addServer = function ($memcache, $scope, $args) {
            // We just care about the first server to add tags
            if (count($args) > 1 && !ObjectKVStore::get($memcache, 'server')) {
                ObjectKVStore::put($memcache, 'server', $args);
            }
        };
        \DDTrace\hook_function('memcache_add_server', $this->wrapClosureForHookFunction($memcache_addServer));
        \DDTrace\hook_method('Memcache', 'addServer', $memcache_addServer);
        \DDTrace\hook_function('memcache_connect', $this->wrapClosureForHookFunction($memcache_addServer));
        \DDTrace\hook_method('Memcache', 'connect', $memcache_addServer);
        \DDTrace\hook_function('memcache_pconnect', $this->wrapClosureForHookFunction($memcache_addServer));
        \DDTrace\hook_method('Memcache', 'pconnect', $memcache_addServer);

        $memcache_cas = function (SpanData $span, $args) use ($integration) {
            $integration->setCommonData($span, 'cas');
            if (isset($args[4])) {
                $span->meta['memcache.cas_token'] = $args[4];
            }
            $span->meta['memcache.query'] = 'cas ?';
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->setServerTags($span, $this);
        };

        \DDTrace\trace_method('Memcache', 'cas', $memcache_cas);
        \DDTrace\trace_function('memcache_cas', $this->wrapClosureForTraceFunction($memcache_cas));


        return Integration::LOADED;
    }

    public function traceCommand($command)
    {
        $integration = $this;
        $trace = function (SpanData $span, $args, $retval) use ($integration, $command) {
            $integration->setCommonData($span, $command);
            if ($command === 'get') {
                $span->metrics[Tag::DB_ROW_COUNT] = empty($retval) ? 0 : 1;
            }
            if (!is_array($args[0])) {
                $integration->setServerTags($span, $this);
                $queryParams = dd_trace_env_config("DD_TRACE_MEMCACHED_OBFUSCATION") ?
                    Obfuscation::toObfuscatedString($args[0]) : $args[0];
                $span->meta['memcache.query'] = $command . ' ' . $queryParams;
            }
            $span->peerServiceSources = DatabaseIntegrationHelper::PEER_SERVICE_SOURCES;
            $integration->markForTraceAnalytics($span, $command);
        };
        \DDTrace\trace_method('Memcache', $command, $trace);
        \DDTrace\trace_function("memcache_$command", $this->wrapClosureForTraceFunction($trace));
    }

    public function wrapClosureForTraceFunction(\Closure $closure)
    {
        return function (SpanData $span, $args, $retval, $exception) use ($closure) {
            $memcache = array_shift($args);
            return $closure->call($memcache, $span, $args, $retval, $exception);
        };
    }

    public function wrapClosureForHookFunction(\Closure $closure)
    {
        return function ($args, $retval, $exception) use ($closure) {
            $memcache = array_shift($args);
            return $closure($memcache, 'Memcache', $args, $retval, $exception);
        };
    }

    /**
     * Sets common values shared by many commands.
     *
     * @param SpanData $span
     * @param string $command
     */
    public function setCommonData(SpanData $span, $command)
    {
        $span->name = "Memcache.$command";
        $span->type = Type::MEMCACHED;
        Integration::handleInternalSpanServiceName($span, MemcacheIntegration::NAME);
        $span->resource = $command;
        $span->meta['memcache.command'] = $command;
        $span->meta[Tag::SPAN_KIND] = 'client';
        $span->meta[Tag::COMPONENT] = MemcacheIntegration::NAME;
        $span->meta[Tag::DB_SYSTEM] = 'memcached';
    }

    /**
     * Add the servers to the span metadata.
     *
     * Do not call `Memcache::getServerByKey()` since it mutates the
     * result code. `Memcache::getServerList()` is more stable
     * because it does not mutate the result code. One side effect to
     * using the more stable API is that it is not possible to identify
     * the specific server used in the original call when there are
     * multiple servers.
     *
     * @param SpanData $span
     * @param \Memcache $memcache
     */
    public function setServerTags(SpanData $span, \Memcache $memcache)
    {
        if ($server = ObjectKVStore::get($memcache, 'server')) {
            list($span->meta[Tag::TARGET_HOST], $span->meta[Tag::TARGET_PORT]) = $server;
        }
    }

    /**
     * @param SpanData $span
     * @param string $command
     */
    public function markForTraceAnalytics(SpanData $span, $command)
    {
        $commandsForAnalytics = [
            'add',
            'delete',
            'get',
            'set',
        ];

        if (in_array($command, $commandsForAnalytics)) {
            $this->addTraceAnalyticsIfEnabled($span);
        }
    }
}
