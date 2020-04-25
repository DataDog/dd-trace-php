<?php

namespace DDTrace\Integrations\APCu;

use DDTrace\Contracts\Span;
use DDTrace\Integrations\Integration;
use DDTrace\Obfuscation;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;

/**
 * Tracing of the APCu library.
 *
 */
class APCuSandboxedIntegration extends Integration
{
    const NAME = 'apcu';

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
        if (!extension_loaded('apcu')) {
            return Integration::NOT_AVAILABLE;
        }

        // bool apc_add ( string $key , mixed $var [, int $ttl = 0 ] )
        // array apc_add ( array $values [, mixed $unused = NULL [, int $ttl = 0 ]] )
        dd_trace('apcu_add', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_add', func_get_args());
        });

        // array apcu_cache_info ([ bool $limited = FALSE ] )
        dd_trace('apcu_cache_info', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_cache_info', func_get_args());
        });

        // bool apcu_cas ( string $key , int $old , int $new )
        dd_trace('apcu_cas', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_cas', func_get_args());
        });

        // bool apcu_clear_cache ( void )
        dd_trace('apcu_clear_cache', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_clear_cache', func_get_args());
        });

        // int apcu_dec ( string $key [, int $step = 1 [, bool &$success [, int $ttl = 0 ]]] )
        dd_trace('apcu_dec', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_dec', func_get_args());
        });

        // mixed apcu_delete ( mixed $key )
        dd_trace('apcu_delete', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_delete', func_get_args());
        });

        // bool apcu_enabled ( void )
        dd_trace('apcu_enabled', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_enabled', func_get_args());
        });

        // mixed apcu_entry ( string $key , callable $generator [, int $ttl = 0 ] )
        dd_trace('apcu_entry', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_entry', func_get_args());
        });

        // mixed apcu_exists ( mixed $keys )
        dd_trace('apcu_exists', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_exists', func_get_args());
        });

        // mixed apcu_fetch ( mixed $key [, bool &$success ] )
        dd_trace('apcu_fetch', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_fetch', func_get_args());
        });

        // int apcu_inc ( string $key [, int $step = 1 [, bool &$success [, int $ttl = 0 ]]] )
        dd_trace('apcu_inc', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_inc', func_get_args());
        });

        // array apcu_sma_info ([ bool $limited = FALSE ] )
        dd_trace('apcu_sma_info', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand('apcu_sma_info', func_get_args());
        });

        // bool apcu_store ( string $key , mixed $var [, int $ttl = 0 ] )
        // array apcu_store ( array $values [, mixed $unused = NULL [, int $ttl = 0 ]] )
        dd_trace('apcu_store', function () {
            if (GlobalTracer::get()->limited()) {
                return dd_trace_forward_call();
            }

            return APCuIntegration::traceCommand( 'apcu_store', func_get_args());
        });

        return Integration::LOADED;
    }

    public static function traceCommand($command, $args)
    {
        if (empty($args)) {
            $args[] = 'void';
        }
        $tracer = GlobalTracer::get();
        $scope = $tracer->startIntegrationScopeAndSpan(
            APCuIntegration::getInstance(),
            "APCu.$command"
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::APCu);
        $span->setTag(Tag::SERVICE_NAME, 'apcu');
        $span->setTag('apcu.command', $command);
        $span->setTag('apcu.query', "$command " . Obfuscation::toObfuscatedString($args[0]));
        $span->setTag(Tag::RESOURCE_NAME, $command);

        APCuIntegration::markForTraceAnalytics($span, $command);

        return TryCatchFinally::executeFunction($scope, $command, $args);
    }

    /**
     * @param Span $span
     * @param string $command
     */
    public static function markForTraceAnalytics(Span $span, $command)
    {
        $commandsForAnalytics = [
            'apcu_add',
            'apcu_cas',
            'apcu_dec',
            'apcu_delete',
            'apcu_exists',
            'apcu_fetch',
            'apcu_inc',
            'apcu_store',
        ];

        if (in_array($command, $commandsForAnalytics)) {
            $span->setTraceAnalyticsCandidate();
        }
    }
}
