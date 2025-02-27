<?php declare(strict_types=1);

namespace FakeApp\Http;

// Really only suitable for simple HTTP 1.0/1.1. Up to the user to get all of
// the headers correct.
final class Response
{

    public function __construct(
        public readonly Status $status,
        public readonly Headers $headers,
        public readonly string $body,
    ) {
        if (\function_exists('Datadog\\Profiling\\trigger_time_sample')) {
            \Datadog\Profiling\trigger_time_sample();
        }
    }

    public static function new(
        Status $status,
        Headers $headers,
        string $body,
    ): Response {
        return new Response($status, $headers, $body);
    }
}
