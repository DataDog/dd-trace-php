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
        "DDTrace\\Log\\PsrLogger" => 'DDTrace/Log/PsrLogger.php',
        "DDTrace\\OpenTracer\\Tracer" => 'DDTrace/OpenTracer/Tracer.php',
        "DDTrace\\OpenTracer\\Span" => 'DDTrace/OpenTracer/Span.php',
        "DDTrace\\OpenTracer\\Scope" => 'DDTrace/OpenTracer/Scope.php',
        "DDTrace\\OpenTracer\\ScopeManager" => 'DDTrace/OpenTracer/ScopeManager.php',
        "DDTrace\\OpenTracer\\SpanContext" => 'DDTrace/OpenTracer/SpanContext.php',
        "DDTrace\\Integrations\\CakePHP\\V2\\CakePHPIntegrationLoader" => 'DDTrace/Integrations/CakePHP/V2/CakePHPIntegrationLoader.php',
        "DDTrace\\Integrations\\Slim\\V3\\SlimIntegrationLoader" => 'DDTrace/Integrations/Slim/V3/SlimIntegrationLoader.php',
        "DDTrace\\Integrations\\Symfony\\V4\\SymfonyBundle" => 'DDTrace/Integrations/Symfony/V4/SymfonyBundle.php',
        "DDTrace\\Integrations\\Symfony\\V3\\SymfonyBundle" => 'DDTrace/Integrations/Symfony/V3/SymfonyBundle.php',
        "DDTrace\\Integrations\\Laravel\\V5\\LaravelIntegrationLoader" => 'DDTrace/Integrations/Laravel/V5/LaravelIntegrationLoader.php',
        "DDTrace\\Integrations\\Laravel\\V4\\LaravelProvider" => 'DDTrace/Integrations/Laravel/V4/LaravelProvider.php',
        "DDTrace\\Log\\NullLogger" => 'DDTrace/Log/NullLogger.php',
        "DDTrace\\NoopTracer" => 'DDTrace/NoopTracer.php',
        "DDTrace\\NoopSpan" => 'DDTrace/NoopSpan.php',
        "DDTrace\\NoopScope" => 'DDTrace/NoopScope.php',
        "DDTrace\\Encoders\\Json" => 'DDTrace/Encoders/Json.php',
        "DDTrace\\Encoders\\Noop" => 'DDTrace/Encoders/Noop.php',
        "DDTrace\\Propagators\\Noop" => 'DDTrace/Propagators/Noop.php',
        "DDTrace\\Transport\\Noop" => 'DDTrace/Transport/Noop.php',
        "DDTrace\\NoopScopeManager" => 'DDTrace/NoopScopeManager.php',
        "DDTrace\\NoopSpanContext" => 'DDTrace/NoopSpanContext.php',
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
