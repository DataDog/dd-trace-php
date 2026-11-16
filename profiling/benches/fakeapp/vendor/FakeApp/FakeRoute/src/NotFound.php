<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

final class NotFound
{

    private function __construct(
        public readonly string $http_method,
        public readonly string $path,
    ) {
    }

    public static function new(string $http_method, string $path): NotFound
    {
        return new NotFound($http_method, $path);
    }
}
