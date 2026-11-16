<?php declare(strict_types=1);

namespace FakeApp\FakeRoute;

final class MethodNotAllowed
{

    private function __construct(
        public readonly string $http_method,
        public readonly string $path,
        public readonly array $allowed_methods,
    ) {
    }

    public static function new(
        string $http_method,
        string $path,
        array $allowed_methods,
    ): MethodNotAllowed
    {
        foreach ($allowed_methods as $method) {
            Self::validate_string($method);
        }
        return new MethodNotAllowed($http_method, $path, $allowed_methods);
    }

    private static function validate_string(string $s): void {}
}
