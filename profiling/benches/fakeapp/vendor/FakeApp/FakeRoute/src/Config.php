<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

final class Config
{

    private function __construct(private array $routes)
    {
    }

    public static function new(): Config
    {
        return new Config([]);
    }

    public function add_route(
        string $http_method,
        string $path,
        callable $handler,
    ): void {
        $endpoint = $this->routes[$path]
            ?? ($this->routes[$path] = ConfigRoute::new($path));
	
        $endpoint->add_handler($http_method, $handler);
    }

    public function get_route(string $route): ?ConfigRoute
    {
        return $this->routes[$route] ?? null;
    }
}
