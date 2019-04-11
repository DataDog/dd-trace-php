<?php

namespace DDTrace\Bridge;

/**
 * Datadog Optional dependency PSR4 authoritative autoloader.
 */
class OptionalDepsAutoloader
{
    /**
     * @var array
     */
    private static $autoloaderMapping = [
        "DDTrace\\Integrations\\ZendFramework\V1\TraceRequest" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/TraceRequest.php',
        "DDTrace\\Log\\PsrLogger" => __DIR__ . '/../src/DDTrace/Log/PsrLogger.php',
        "DDTrace\\OpenTracer\\Tracer" => __DIR__ . '/../src/DDTrace/OpenTracer/Tracer.php',
        "DDTrace\\OpenTracer\\Span" => __DIR__ . '/../src/DDTrace/OpenTracer/Span.php',
        "DDTrace\\OpenTracer\\Scope" => __DIR__ . '/../src/DDTrace/OpenTracer/Scope.php',
        "DDTrace\\OpenTracer\\ScopeManager" => __DIR__ . '/../src/DDTrace/OpenTracer/ScopeManager.php',
        "DDTrace\\OpenTracer\\SpanContext" => __DIR__ . '/../src/DDTrace/OpenTracer/SpanContext.php',
        "DDTrace\\Integrations\\Symfony\\V4\\SymfonyBundle" => __DIR__ . '/../src/DDTrace/Integrations/Symfony/V4/SymfonyBundle.php',
        "DDTrace\\Integrations\\Symfony\\V3\\SymfonyBundle" => __DIR__ . '/../src/DDTrace/Integrations/Symfony/V3/SymfonyBundle.php',
        "DDTrace\\Integrations\\Laravel\\V5\\LaravelIntegrationLoader" => __DIR__ . '/../src/DDTrace/Integrations/Laravel/V5/LaravelIntegrationLoader.php',
        "DDTrace\\Integrations\\Laravel\\V4\\LaravelProvider" => __DIR__ . '/../src/DDTrace/Integrations/Laravel/V4/LaravelProvider.php',
        "DDTrace\\Log\\NullLogger" => __DIR__ . '/../src/DDTrace/Log/NullLogger.php',
        "DDTrace\\NoopTracer" => __DIR__ . '/../src/DDTrace/NoopTracer.php',
        "DDTrace\\NoopSpan" => __DIR__ . '/../src/DDTrace/NoopSpan.php',
        "DDTrace\\NoopScope" => __DIR__ . '/../src/DDTrace/NoopScope.php',
        "DDTrace\\Encoders\\Json" => __DIR__ . '/../src/DDTrace/Encoders/Json.php',
        "DDTrace\\Encoders\\Noop" => __DIR__ . '/../src/DDTrace/Encoders/Noop.php',
        "DDTrace\\Propagators\\Noop" => __DIR__ . '/../src/DDTrace/Propagators/Noop.php',
        "DDTrace\\Transport\\Noop" => __DIR__ . '/../src/DDTrace/Transport/Noop.php',
        "DDTrace\\NoopScopeManager" => __DIR__ . '/../src/DDTrace/NoopScopeManager.php',
        "DDTrace\\NoopSpanContext" => __DIR__ . '/../src/DDTrace/NoopSpanContext.php',
    ];

    /**
     * @param string $class
     */
    public static function load($class)
    {
        if (array_key_exists($class, self::$autoloaderMapping)) {
            require_once self::$autoloaderMapping[$class];
        }
    }
}
