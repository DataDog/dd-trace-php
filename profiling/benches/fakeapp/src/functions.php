<?php declare(strict_types=1);

namespace App;

use FakeApp\Http\{Headers, Response, Status};

function home(string $http_method, string $uri): Response
{
    $status = Status::new('HTTP/1.1', 200, 'Found');
    $body = "Welcome to /\n";
    $headers = Headers::new()
        ->append('Content-Type', 'text/plain')
        ->append('Content-Length', (string)\strlen($body));
    return Response::new($status, $headers, $body);
}

function blog_index(string $http_method, string $uri): Response
{
    $status = Status::new('HTTP/1.1', 200, 'Found');
    $body = "Blog Index\n - 1\n - 2\n";
    $headers = Headers::new()
        ->append('Content-Type', 'text/plain')
        ->append('Content-Length', (string)\strlen($body));
    return Response::new($status, $headers, $body);
}

function blog_article(string $http_method, string $uri): Response
{
    $status = Status::new('HTTP/1.1', 200, 'Found');
    $body = "Blog Article\n";
    $headers = Headers::new()
        ->append('Content-Type', 'text/plain')
        ->append('Content-Length', (string)\strlen($body));
    return Response::new($status, $headers, $body);
}

function about_us(string $http_method, string $uri): Response
{
    $status = Status::new('HTTP/1.1', 200, 'Found');
    $body = "About Us\n\nTODO\n";
    $headers = Headers::new()
        ->append('Content-Type', 'text/plain')
        ->append('Content-Length', (string)\strlen($body));
    return Response::new($status, $headers, $body);
}

if (\function_exists('Datadog\\Profiling\\trigger_time_sample')) {
    \Datadog\Profiling\trigger_time_sample();
}
