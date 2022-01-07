<?php

namespace DDTrace\Integrations\Memcached;

use DDTrace\Integrations\Integration;
use DDTrace\Obfuscation;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

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

    public function init()
    {
        if (!extension_loaded('memcached')) {
            return Integration::NOT_AVAILABLE;
        }
        $integration = $this;

        $this->traceCommand('add');
        $this->traceCommandByKey('addByKey');

        $this->traceCommand('append');
        $this->traceCommandByKey('appendByKey');

        $this->traceCommand('decrement');
        $this->traceCommandByKey('decrementByKey');

        $this->traceCommand('delete');
        $this->traceMulti('deleteMulti');
        $this->traceCommandByKey('deleteByKey');
        $this->traceMultiByKey('deleteMultiByKey');

        $this->traceCommand('get');
        $this->traceMulti('getMulti');
        $this->traceCommandByKey('getByKey');
        $this->traceMultiByKey('getMultiByKey');

        $this->traceCommand('set');
        $this->traceMulti('setMulti');
        $this->traceCommandByKey('setByKey');
        $this->traceMultiByKey('setMultiByKey');

        $this->traceCommand('increment');
        $this->traceCommandByKey('incrementByKey');

        $this->traceCommand('prepend');
        $this->traceCommandByKey('prependByKey');

        $this->traceCommand('replace');
        $this->traceCommandByKey('replaceByKey');

        $this->traceCommand('touch');
        $this->traceCommandByKey('touchByKey');

        \DDTrace\trace_method('Memcached', 'flush', function (SpanData $span) use ($integration) {
            $integration->setCommonData($span, 'flush');
        });

        \DDTrace\trace_method('Memcached', 'cas', function (SpanData $span, $args) use ($integration) {
            $integration->setCommonData($span, 'cas');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'cas ?';
            $integration->setServerTags($span, $this);
        });

        \DDTrace\trace_method('Memcached', 'casByKey', function (SpanData $span, $args) use ($integration) {
            $integration->setCommonData($span, 'casByKey');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'casByKey ?';
            $span->meta['memcached.server_key'] = $args[1];

            $integration->setServerTags($span, $this);
        });

        return Integration::LOADED;
    }

    public function traceCommand($command)
    {
        $integration = $this;
        \DDTrace\trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTags($span, $this);
                $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0]);
            }

            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceCommandByKey($command)
    {
        $integration = $this;
        \DDTrace\trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTags($span, $this);
                $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0]);
                $span->meta['memcached.server_key'] = $args[0];
            }

            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceMulti($command)
    {
        $integration = $this;
        \DDTrace\trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTags($span, $this);
            }
            $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0], ',');
            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceMultiByKey($command)
    {
        $integration = $this;
        \DDTrace\trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            $integration->setCommonData($span, $command);
            $span->meta['memcached.server_key'] = $args[0];
            $integration->setServerTags($span, $this);
            $query = "$command " . Obfuscation::toObfuscatedString($args[1], ',');
            $span->meta['memcached.query'] = $query;
            $integration->markForTraceAnalytics($span, $command);
        });
    }

    /**
     * Sets common values shared by many commands.
     *
     * @param SpanData $span
     * @param string $command
     */
    public function setCommonData(SpanData $span, $command)
    {
        $span->name = "Memcached.$command";
        $span->type = Type::MEMCACHED;
        $span->service = 'memcached';
        $span->resource = $command;
        $span->meta['memcached.command'] = $command;
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
    public function setServerTags(SpanData $span, \Memcached $memcached)
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
    public function markForTraceAnalytics(SpanData $span, $command)
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
            $this->addTraceAnalyticsIfEnabled($span);
        }
    }
}
