<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

use Closure;

final class Found
{
    private function __construct(
        public readonly string $http_method,
        public readonly string $path,
        public readonly Closure $handler,
    ) {   
    }   

    public static function new(
        string $http_method,
        string $path,
        Closure $handler,
    ): Found {
        return new Found($http_method, $path, $handler);
    }
}
