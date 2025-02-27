<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

final class MatchedRoute
{

    private function __construct(private array $handlers)
    {
    }

    public static function new(array $handlers): Config
    {
        foreach ($handlers as $http_method => $handler) {
            Self::validate_handler($handlers);
        }
        return new Config($handlers);
    }

    private static function validate_handler(
        string $http_method,
        Closure $handler
    ): void {
    }

    public function get_handler(mixed $http_method): ?Closure
    {
        return $this->handlers[$http_method] ?? null;
    }

    public function allowed_methods(): array
    {
        return \array_keys($this->handlers);
    }
}
