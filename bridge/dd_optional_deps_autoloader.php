<?php

namespace DDTrace\Bridge;

/**
 * Datadog psr4 autoloader.
 */
class RequireOnceAutoloader
{
    /**
     * @var array
     */
    private static $autoloaderMapping = [
        "DDTrace\\Integrations\\ZendFramework\V1\TraceRequest" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/TraceRequest.php',
        "DDTrace_Ddtrace" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/Ddtrace.php',
        "DDTrace\\Log\\PsrLogger" => __DIR__ . '/../src/DDTrace/Log/PsrLogger.php',
        "DDTrace\\OpenTracer\\Tracer" => __DIR__ . '/../src/DDTrace/OpenTracer/Tracer.php',
        "DDTrace\\OpenTracer\\Span" => __DIR__ . '/../src/DDTrace/OpenTracer/Span.php',
        "DDTrace\\OpenTracer\\Scope" => __DIR__ . '/../src/DDTrace/OpenTracer/Scope.php',
        "DDTrace\\OpenTracer\\ScopeManager" => __DIR__ . '/../src/DDTrace/OpenTracer/ScopeManager.php',
        "DDTrace\\OpenTracer\\SpanContext" => __DIR__ . '/../src/DDTrace/OpenTracer/SpanContext.php',
        "DDTrace\\Integrations\\Symfony\\V4\\SymfonyBundle" => __DIR__ . '/../src/DDTrace/Integrations/Symfony/V4/SymfonyBundle.php',
        "DDTrace\Integrations\Symfony\V3\SymfonyBundle" => __DIR__ . '/../src/DDTrace/Integrations/Symfony/V3/SymfonyBundle.php',
        "" => __DIR__ . '/../src/DDTrace/Integrations/Laravel/V5/LaravelIntegrationLoader.php',
        "" => __DIR__ . '/../src/DDTrace/Integrations/Laravel/V4/LaravelProvider.php',
        "" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/TraceRequest.php',
        "" => __DIR__ . '/../src/DDTrace/Integrations/ZendFramework/V1/Ddtrace.php',
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
