<?php declare(strict_types=1);

namespace FakeApp\Http;

final class Status
{
    private function __construct(
        public readonly string $protocol,
        public readonly int $code,
        public readonly string $message,
    ) {
    }

    public static function new(
	string $protocol,
        int $code,
        string $message = "",
    ): Status {
        return new Status($protocol, $code, $message);
    }
}
