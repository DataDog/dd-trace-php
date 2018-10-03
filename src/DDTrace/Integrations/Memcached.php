<?php

namespace DDTrace\Integrations;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\GlobalTracer;

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
class Memcached
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Memcached tracing.', E_USER_WARNING);
            return;
        }

        // bool Memcached::add ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'add', function (...$args) {
            return Memcached::traceCommand($this, 'add', $args);
        });

        // bool Memcached::addByKey ( string $server_key , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'addByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'add', $args);
        });

        // bool Memcached::append ( string $key , string $value )
        dd_trace('Memcached', 'append', function (...$args) {
            return Memcached::traceCommand($this, 'append', $args);
        });

        // bool Memcached::appendByKey ( string $server_key , string $key , string $value )
        dd_trace('Memcached', 'appendByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'appendByKey', $args);
        });

        // bool Memcached::cas ( float $cas_token , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'cas', function (...$args) {
            return Memcached::traceCas($this, $args);
        });

        // bool Memcached::casByKey ( float $cas_token , string $server_key , string $key , mixed $value
        //     [, int $expiration ] )
        dd_trace('Memcached', 'casByKey', function (...$args) {
            return Memcached::traceCasByKey($this, $args);
        });

        // int Memcached::decrement ( string $key [, int $offset = 1 [, int $initial_value = 0
        //     [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'decrement', function (...$args) {
            return Memcached::traceCommand($this, 'decrement', $args);
        });

        // int Memcached::decrementByKey ( string $server_key , string $key
        //      [, int $offset = 1 [, int $initial_value = 0 [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'decrementByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'decrementByKey', $args);
        });

        // bool Memcached::delete ( string $key [, int $time = 0 ] )
        dd_trace('Memcached', 'delete', function (...$args) {
            return Memcached::traceCommand($this, 'delete', $args);
        });

        // bool Memcached::deleteByKey ( string $server_key , string $key [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'deleteByKey', $args);
        });

        // array Memcached::deleteMulti ( array $keys [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteMulti', function (...$args) {
            return Memcached::traceMulti($this, 'deleteMulti', $args);
        });

        // bool Memcached::deleteMultiByKey ( string $server_key , array $keys [, int $time = 0 ] )
        dd_trace('Memcached', 'deleteMultiByKey', function (...$args) {
            return Memcached::traceMultiByKey($this, 'deleteMultiByKey', $args);
        });

        // bool Memcached::flush ([ int $delay = 0 ] )
        dd_trace('Memcached', 'flush', function (...$args) {
            $scope = GlobalTracer::get()->startActiveSpan('Memcached.flush');
            $span = $scope->getSpan();
            $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
            $span->setTag(Tags\SERVICE_NAME, 'memcached');
            $span->setTag('memcached.command', 'flush');
            $span->setResource('flush');

            try {
                return $this->flush(...$args);
            } catch (\Exception $e) {
                $span->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // mixed Memcached::get ( string $key [, callable $cache_cb [, int &$flags ]] )
        dd_trace('Memcached', 'get', function (...$args) {
            return Memcached::traceCommand($this, 'get', $args);
        });

        // mixed Memcached::getByKey ( string $server_key , string $key [, callable $cache_cb [, int $flags ]] )
        dd_trace('Memcached', 'getByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'getByKey', $args);
        });

        // mixed Memcached::getMulti ( array $keys [, int $flags ] )
        dd_trace('Memcached', 'getMulti', function (...$args) {
            return Memcached::traceCommand($this, 'getMulti', $args);
        });

        // array Memcached::getMultiByKey ( string $server_key , array $keys [, int $flags ] )
        dd_trace('Memcached', 'getMultiByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'getMultiByKey', $args);
        });

        // int Memcached::increment ( string $key [, int $offset = 1 [, int $initial_value = 0
        //     [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'increment', function (...$args) {
            return Memcached::traceCommand($this, 'increment', $args);
        });

        // int Memcached::incrementByKey ( string $server_key , string $key [, int $offset = 1
        //     [, int $initial_value = 0 [, int $expiry = 0 ]]] )
        dd_trace('Memcached', 'incrementByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'incrementByKey', $args);
        });

        // bool Memcached::prepend ( string $key , string $value )
        dd_trace('Memcached', 'prepend', function (...$args) {
            return Memcached::traceCommand($this, 'prepend', $args);
        });

        // bool Memcached::prependByKey ( string $server_key , string $key , string $value )
        dd_trace('Memcached', 'prependByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'prependByKey', $args);
        });

        // bool Memcached::replace ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'replace', function (...$args) {
            return Memcached::traceCommand($this, 'replace', $args);
        });

        // bool Memcached::replaceByKey ( string $server_key , string $key , mixed $value [, int $expiration  ] )
        dd_trace('Memcached', 'replaceByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'replaceByKey', $args);
        });

        // bool Memcached::set ( string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'set', function (...$args) {
            return Memcached::traceCommand($this, 'set', $args);
        });

        // bool Memcached::setByKey ( string $server_key , string $key , mixed $value [, int $expiration ] )
        dd_trace('Memcached', 'setByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'setByKey', $args);
        });

        // bool Memcached::setMulti ( array $items [, int $expiration ] )
        dd_trace('Memcached', 'setMulti', function (...$args) {
            return Memcached::traceMulti($this, 'setMulti', $args);
        });

        // bool Memcached::setMultiByKey ( string $server_key , array $items [, int $expiration ] )
        dd_trace('Memcached', 'setMultiByKey', function (...$args) {
            return Memcached::traceMultiByKey($this, 'setMultiByKey', $args);
        });

        // bool Memcached::touch ( string $key , int $expiration )
        dd_trace('Memcached', 'touch', function (...$args) {
            return Memcached::traceCommand($this, 'touch', $args);
        });

        // bool Memcached::touchByKey ( string $server_key , string $key , int $expiration )
        dd_trace('Memcached', 'touchByKey', function (...$args) {
            return Memcached::traceCommandByKey($this, 'touchByKey', $args);
        });
    }

    public static function traceCommand($memcached, $command, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan("Memcached.$command");
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);

        if (is_array($args[0])) {
            $key = implode(' ', $args[0]);
        } else {
            $key = $args[0];
            self::setServerTagsByKey($span, $memcached, $key);
        }
        $span->setTag('memcached.query', "$command $key");
        $span->setResource($command);

        try {
            return $memcached->$command(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
    }

    public static function traceCommandByKey($memcached, $command, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan("Memcached.$command");
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);
        $span->setTag('memcached.server_key', $args[0]);
        self::setServerTagsByKey($span, $memcached, $args[0]);

        $key = is_array($args[1]) ? implode(' ', $args[1]) : $args[1];
        $span->setTag('memcached.query', "$command $key");
        $span->setResource($command);

        try {
            return $memcached->$command(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
    }

    public static function traceCas($memcached, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan('Memcached.cas');
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', 'cas');
        $span->setTag('memcached.cas_token', $args[0]);

        $key = $args[1];
        self::setServerTagsByKey($span, $memcached, $key);
        $span->setTag('memcached.query', "cas $key");
        $span->setResource('cas');

        try {
            return $memcached->cas(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
    }

    public static function traceCasByKey($memcached, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan('Memcached.casByKey');
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', 'casByKey');
        $span->setTag('memcached.cas_token', $args[0]);

        $serverKey = $args[1];
        $key = $args[2];
        $span->setTag('memcached.server_key', $serverKey);
        $span->setTag('memcached.query', "casByKey $key");
        $span->setResource('casByKey');
        self::setServerTagsByKey($span, $memcached, $serverKey);

        try {
            return $memcached->casByKey(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
    }

    public static function traceMulti($memcached, $command, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan("Memcached.$command");
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);

        $query = "$command " . implode(',', array_keys($args[0]));
        $span->setTag('memcached.query', $query);
        $span->setResource($command);

        try {
            return $memcached->$command(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
    }

    public static function traceMultiByKey($memcached, $command, $args)
    {
        $scope = GlobalTracer::get()->startActiveSpan("Memcached.$command");
        $span = $scope->getSpan();
        $span->setTag(Tags\SPAN_TYPE, Types\MEMCACHED);
        $span->setTag(Tags\SERVICE_NAME, 'memcached');
        $span->setTag('memcached.command', $command);
        $span->setTag('memcached.server_key', $args[0]);
        self::setServerTagsByKey($span, $memcached, $args[0]);

        $query = "$command " . implode(',', array_keys($args[1]));
        $span->setTag('memcached.query', $query);
        $span->setResource($command);

        try {
            return $memcached->$command(...$args);
        } catch (\Exception $e) {
            $span->setError($e);
            throw $e;
        } finally {
            $scope->close();
        }
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
    public static function setServerTagsByKey($span, $memcached, $key)
    {
        $server = $memcached->getServerByKey($key);
        $span->setTag(Tags\TARGET_HOST, $server['host']);
        $span->setTag(Tags\TARGET_PORT, $server['port']);
    }
}
