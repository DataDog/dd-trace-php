<?php

namespace DDTrace\Integrations\Memcached;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
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
class MemcachedSandboxedIntegration extends SandboxedIntegration
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
            // Memcached is provided through an extension and not through a class loader.
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

        dd_trace_method('Memcached', 'flush', function (SpanData $span) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, 'flush');
        });

        dd_trace_method('Memcached', 'cas', function (SpanData $span, $args) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, 'cas');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'cas ?';
            $integration->setServerTagsByKey($span, $this, $args[1]);
        });

        dd_trace_method('Memcached', 'casByKey', function (SpanData $span, $args) use ($integration) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, 'casByKey');
            $span->meta['memcached.cas_token'] = $args[0];
            $span->meta['memcached.query'] = 'casByKey ?';
            $span->meta['memcached.server_key'] = $args[1];

            $integration->setServerTagsByKey($span, $this, $args[0]);
        });

        return Integration::LOADED;
    }

    public function traceCommand($command)
    {
        $integration = $this;
        dd_trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTagsByKey($span, $this, $args[0]);
                $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0]);
            }

            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceCommandByKey($command)
    {
        $integration = $this;
        dd_trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTagsByKey($span, $this, $args[0]);
                $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0]);
                $span->meta['memcached.server_key'] = (string)$args[0];
            }

            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceMulti($command)
    {
        $integration = $this;
        dd_trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, $command);
            if (!is_array($args[0])) {
                $integration->setServerTagsByKey($span, $this, $args[0]);
            }
            $span->meta['memcached.query'] = $command . ' ' . Obfuscation::toObfuscatedString($args[0], ',');
            $integration->markForTraceAnalytics($span, $command);
        });
    }

    public function traceMultiByKey($command)
    {
        $integration = $this;
        dd_trace_method('Memcached', $command, function (SpanData $span, $args) use ($integration, $command) {
            if (dd_trace_tracer_is_limited()) {
                return false;
            }
            $integration->setCommonData($span, $command);
            $span->meta['memcached.server_key'] = (string)$args[0];
            $integration->setServerTagsByKey($span, $this, $args[0]);
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
     * Memcached::getServerByKey() /might/ return incorrect information if the
     * distribution would be rebuilt on a real call (Memcached::get(),
     * Memcached::getByKey(), and other commands that actually hit the server
     * include logic to regenerate the distribution if a server has been ejected
     * or if a timer expires; Memcached::getServerByKey() does not check for the
     * distribution being rebuilt. Getting around that would likely be
     * prohibitively expensive though.
     */
    public function setServerTagsByKey(SpanData $span, $memcached, $key)
    {
        $server = $memcached->getServerByKey($key);

        // getServerByKey() might return `false`: https://www.php.net/manual/en/memcached.getserverbykey.php
        if (!is_array($server)) {
            return;
        }

        $span->meta[Tag::TARGET_HOST] = $server['host'];
        $span->meta[Tag::TARGET_PORT] = (string)$server['port'];
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
