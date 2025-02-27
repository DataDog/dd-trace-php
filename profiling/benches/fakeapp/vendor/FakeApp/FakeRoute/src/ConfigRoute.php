<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

use Closure;

final class ConfigRoute
{
    private array $handlers = [];

    private function __construct(private string $route)
    {
    }

    public static function new(string $route): ConfigRoute
    {
        return new ConfigRoute($route);
    }

    public function add_handler(string $http_method, callable $handler): void
    {
        $this->handlers[$http_method] = \Closure::fromCallable($handler);
    }

    public function get_handlers(): array
    {
        return $this->handlers;
    }

    public function allowed_methods(): array
    {
        return \array_keys($this->handlers);
    }

    public function get_handler(string $http_method): ?Closure
    {
        return $this->handlers[$http_method] ?? null;
    }

}
