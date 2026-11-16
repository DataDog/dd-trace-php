<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

final class Dispatcher
{
    private function __construct(private Config $config)
    {
    }

    public static function new(callable $callable): Dispatcher
    {
        $config = Config::new(); 
        $callable($config);
        return new Dispatcher($config);
    }

    public function dispatch(
        string $http_method,
        string $path,
    ): Found|NotFound|MethodNotAllowed {
        if (\function_exists('Datadog\\Profiling\\trigger_time_sample')) {
            \Datadog\Profiling\trigger_time_sample();
        }
        $route = $this->config->get_route($path);
        if ($route === null) {
            return NotFound::new($http_method, $path);
        }

        $handler = $route->get_handler($http_method);
        if ($handler === null) {
            return MethodNotAllowed::new(
                $http_method,
                $path,
                $route->allowed_methods()
            );
        }

        return Found::new($http_method, $path, $handler);
        
    }
}
