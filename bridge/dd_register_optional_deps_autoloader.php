<?php

namespace DDTrace\Bridge;

// phpcs:disable Generic.Files.LineLength.TooLong

/**
 * Datadog Optional dependency PSR4 authoritative autoloader.
 */
class OptionalDepsAutoloader
{
    /**
     * @var array
     */
    private static $autoloaderMapping = [
        "DDTrace\\Integrations\\ZendFramework\\V1\\TraceRequest" => 'DDTrace/Integrations/ZendFramework/V1/TraceRequest.php',
        "DDTrace\\Log\\PsrLogger" => 'api/Log/PsrLogger.php',
        "DDTrace\\OpenTracer\\Tracer" => 'DDTrace/OpenTracer/Tracer.php',
        "DDTrace\\OpenTracer\\Span" => 'DDTrace/OpenTracer/Span.php',
        "DDTrace\\OpenTracer\\Scope" => 'DDTrace/OpenTracer/Scope.php',
        "DDTrace\\OpenTracer\\ScopeManager" => 'DDTrace/OpenTracer/ScopeManager.php',
        "DDTrace\\OpenTracer\\SpanContext" => 'DDTrace/OpenTracer/SpanContext.php',
        "DDTrace\\NoopTracer" => 'api/NoopTracer.php',
        "DDTrace\\NoopSpan" => 'api/NoopSpan.php',
        "DDTrace\\NoopScope" => 'api/NoopScope.php',
        "DDTrace\\Encoders\\Json" => 'DDTrace/Encoders/Json.php',
        "DDTrace\\Encoders\\Noop" => 'DDTrace/Encoders/Noop.php',
        "DDTrace\\Propagators\\Noop" => 'DDTrace/Propagators/Noop.php',
        "DDTrace\\Transport\\Noop" => 'DDTrace/Transport/Noop.php',
        "DDTrace\\NoopScopeManager" => 'api/NoopScopeManager.php',
        "DDTrace\\NoopSpanContext" => 'api/NoopSpanContext.php',
    ];

    /**
     * @param string $class
     */
    public static function load($class)
    {
        if (array_key_exists($class, self::$autoloaderMapping)) {
            require __DIR__ . '/../src/' . self::$autoloaderMapping[$class];
        }
    }
}

// Registering it
spl_autoload_register('DDTrace\Bridge\OptionalDepsAutoloader::load', true, true);
