<?php

namespace DDTrace\Integrations\Memcached;

use DDTrace\Contracts\Span;
use DDTrace\Integrations\Integration;
use DDTrace\Obfuscation;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;

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

    public static function load()
    {
        if (!extension_loaded('memcached')) {
            // Memcached is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }

        // bool Memcached::add ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'add', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'add', func_get_args());
        });

        // bool Memcached::addByKey ( string $server_key , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'addByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'addByKey', func_get_args());
        });

        // bool Memcached::append ( string $key , string $value )
        dd_trace('Memcached', 'append', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'append', func_get_args());
        });

        // bool Memcached::appendByKey ( string $server_key , string $key , string $value )
        dd_trace('Memcached', 'appendByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'appendByKey', func_get_args());
        });

        // bool Memcached::cas ( float $cas_token , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'cas', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCas($this, func_get_args());
        });

        // bool Memcached::casByKey ( float $cas_token , string $server_key , string $key , mixed $value
        //     [, int $expiration ] )
        dd_trace('Memcached', 'casByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCasByKey($this, func_get_args());
        });

        // int Memcached::decrement ( string $key [, int $offset = 1 [, int $initial_value = 0
        //     [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'decrement', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'decrement', func_get_args());
        });

        // int Memcached::decrementByKey ( string $server_key , string $key
        //      [, int $offset = 1 [, int $initial_value = 0 [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'decrementByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'decrementByKey', func_get_args());
        });

        // bool Memcached::delete ( string $key [, int $time = 0 ] )
        dd_trace('Memcached', 'delete', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'delete', func_get_args());
        });

        // bool Memcached::deleteByKey ( string $server_key , string $key [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'deleteByKey', func_get_args());
        });

        // array Memcached::deleteMulti ( array $keys [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteMulti', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMulti($this, 'deleteMulti', func_get_args());
        });

        // bool Memcached::deleteMultiByKey ( string $server_key , array $keys [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteMultiByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMultiByKey($this, 'deleteMultiByKey', func_get_args());
        });

        // bool Memcached::flush ([ int $delay = 0 ] )
        dd_trace('Memcached', 'flush', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                MemcachedIntegration::getInstance(),
                'Memcached.flush'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
            $span->setTag(Tag::SERVICE_NAME, 'memcached');
            $span->setTag('memcached.command', 'flush');
            $span->setTag(Tag::RESOURCE_NAME, 'flush');

            return TryCatchFinally::executePublicMethod($scope, $this, 'flush', func_get_args());
        });

        // mixed Memcached::get ( string $key [, callable $cache_cb [, int &$flags ]] )
        dd_trace('Memcached', 'get', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'get', func_get_args());
        });

        // mixed Memcached::getByKey ( string $server_key , string $key [, callable $cache_cb [, int $flags ]] )
        dd_trace('Memcached', 'getByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'getByKey', func_get_args());
        });

        // mixed Memcached::getMulti ( array $keys [, int $flags ] )
        dd_trace('Memcached', 'getMulti', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMulti($this, 'getMulti', func_get_args());
        });

        // array Memcached::getMultiByKey ( string $server_key , array $keys [, int $flags ] )
        dd_trace('Memcached', 'getMultiByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMultiByKey($this, 'getMultiByKey', func_get_args());
        });

        // int Memcached::increment ( string $key [, int $offset = 1 [, int $initial_value = 0
        //     [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'increment', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'increment', func_get_args());
        });

        // int Memcached::incrementByKey ( string $server_key , string $key [, int $offset = 1
        //     [, int $initial_value = 0 [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'incrementByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'incrementByKey', func_get_args());
        });

        // bool Memcached::prepend ( string $key , string $value )
        dd_trace('Memcached', 'prepend', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'prepend', func_get_args());
        });

        // bool Memcached::prependByKey ( string $server_key , string $key , string $value )
        dd_trace('Memcached', 'prependByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'prependByKey', func_get_args());
        });

        // bool Memcached::replace ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'replace', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'replace', func_get_args());
        });

        // bool Memcached::replaceByKey ( string $server_key , string $key , mixed $value [, int $expiration  ] )
        dd_trace('Memcached', 'replaceByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'replaceByKey', func_get_args());
        });

        // bool Memcached::set ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'set', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'set', func_get_args());
        });

        // bool Memcached::setByKey ( string $server_key , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'setByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'setByKey', func_get_args());
        });

        // bool Memcached::setMulti ( array $items [, int $expiration ] )
        dd_trace('Memcached', 'setMulti', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMulti($this, 'setMulti', func_get_args());
        });

        // bool Memcached::setMultiByKey ( string $server_key , array $items [, int $expiration ] )
        dd_trace('Memcached', 'setMultiByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceMultiByKey($this, 'setMultiByKey', func_get_args());
        });

        // bool Memcached::touch ( string $key , int $expiration )
        dd_trace('Memcached', 'touch', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommand($this, 'touch', func_get_args());
        });

        // bool Memcached::touchByKey ( string $server_key , string $key , int $expiration )
        dd_trace('Memcached', 'touchByKey', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return MemcachedIntegration::traceCommandByKey($this, 'touchByKey', func_get_args());
        });

        return Integration::LOADED;
    }

    public static function traceCommand($memcached, $command, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            "Memcached.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);

        if (!is_array($args[0])) {
            self::setServerTagsByKey($span, $memcached, $args[0]);
        }
        $span->setTag('memcached.query', "$command " . Obfuscation::toObfuscatedString($args[0]));
        $span->setTag(Tag::RESOURCE_NAME, $command);

        MemcachedIntegration::markForTraceAnalytics($span, $command);

        return TryCatchFinally::executePublicMethod($scope, $memcached, $command, $args);
    }

    public static function traceCommandByKey($memcached, $command, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            "Memcached.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);
        $span->setTag('memcached.server_key', $args[0]);
        self::setServerTagsByKey($span, $memcached, $args[0]);

        $span->setTag('memcached.query', "$command " . Obfuscation::toObfuscatedString($args[1]));
        $span->setTag(Tag::RESOURCE_NAME, $command);

        MemcachedIntegration::markForTraceAnalytics($span, $command);

        return TryCatchFinally::executePublicMethod($scope, $memcached, $command, $args);
    }

    public static function traceCas($memcached, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            'Memcached.cas'
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', 'cas');
        $span->setTag('memcached.cas_token', $args[0]);

        self::setServerTagsByKey($span, $memcached, $args[1]);
        $span->setTag('memcached.query', 'cas ?');
        $span->setTag(Tag::RESOURCE_NAME, 'cas');

        return TryCatchFinally::executePublicMethod($scope, $memcached, 'cas', $args);
    }

    public static function traceCasByKey($memcached, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            'Memcached.casByKey'
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', 'casByKey');
        $span->setTag('memcached.cas_token', $args[0]);

        $serverKey = $args[1];
        $span->setTag('memcached.server_key', $serverKey);
        $span->setTag('memcached.query', 'casByKey ?');
        $span->setTag(Tag::RESOURCE_NAME, 'casByKey');
        self::setServerTagsByKey($span, $memcached, $serverKey);

        return TryCatchFinally::executePublicMethod($scope, $memcached, 'casByKey', $args);
    }

    public static function traceMulti($memcached, $command, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            "Memcached.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);

        $query = "$command " . Obfuscation::toObfuscatedString($args[0], ',');
        $span->setTag('memcached.query', $query);
        $span->setTag(Tag::RESOURCE_NAME, $command);

        return TryCatchFinally::executePublicMethod($scope, $memcached, $command, $args);
    }

    public static function traceMultiByKey($memcached, $command, $args)
    {
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            MemcachedIntegration::getInstance(),
            "Memcached.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::MEMCACHED);
        $span->setTag(Tag::SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);
        $span->setTag('memcached.server_key', $args[0]);
        self::setServerTagsByKey($span, $memcached, $args[0]);

        $query = "$command " . Obfuscation::toObfuscatedString($args[1], ',');
        $span->setTag('memcached.query', $query);
        $span->setTag(Tag::RESOURCE_NAME, $command);

        return TryCatchFinally::executePublicMethod($scope, $memcached, $command, $args);
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
    private static function setServerTagsByKey($span, $memcached, $key)
    {
        $server = $memcached->getServerByKey($key);
        $span->setTag(Tag::TARGET_HOST, $server['host']);
        $span->setTag(Tag::TARGET_PORT, $server['port']);
    }

    /**
     * @param Span $span
     * @param string $command
     */
    public static function markForTraceAnalytics(Span $span, $command)
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
            $span->setTraceAnalyticsCandidate();
        }
    }
}
