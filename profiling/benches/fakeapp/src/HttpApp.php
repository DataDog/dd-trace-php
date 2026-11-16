<?php declare(strict_types=1);

namespace App;

use FakeApp\FakeRoute;
use FakeApp\Http;

final class HttpApp
{

    private function __construct(private FakeRoute\Dispatcher $dispatcher)
    {
    }

    public static function new(): HttpApp
    {
        $dispatcher = FakeRoute\Dispatcher::new(
            function (FakeRoute\Config $config) {
                $config->add_route('GET', '/', 'App\\home');
                $config->add_route('GET', '/blog', 'App\\blog_index');
                $config->add_route('POST', '/about', 'App\\about_us');

                // Our fake router sucks, can't do regexp or anything ^_^
                $config->add_route('GET', '/blog/1', 'App\\blog_article');
                $config->add_route('GET', '/blog/2', 'App\\blog_article');
            });
        return new HttpApp($dispatcher);
    }

    public function run(string $http_method, string $uri): void {
        try {
            $result = $this->dispatcher->dispatch($http_method, $uri);
            if ($result instanceof FakeRoute\NotFound) {
                $status = Http\Status::new('HTTP/1.1', 405, 'Method Not Allowed');
                $body = "Not Found\n\n$http_method $uri\n";
                $headers = Http\Headers::new()
                    ->append('Content-Type', 'text/plain')
                    ->append('Content-Length', (string)\strlen($body));
                $response = Http\Response::new($status, $headers, $body);
            } elseif ($result instanceof FakeRoute\MethodNotAllowed) {
                $status = Http\Status::new('HTTP/1.1', 405, 'Method Not Allowed');
                $body = "Method Not Allowed\n\nAllowed Methods:\n";
                foreach ($result->allowed_methods as $method) {
                    $body .= " - $method\n";
                }
                $headers = Http\Headers::new()
                    ->append('Content-Type', 'text/plain')
                    ->append('Content-Length', (string)\strlen($body));
                $response = Http\Response::new($status, $headers, $body);
            } else {            
                assert($result instanceof FakeRoute\Found);
                $response = ($result->handler)($http_method, $uri);
            }
        } catch (Throwable $err) {
            $status = Http\Status::new('HTTP/1.1', 500, 'Internal Server Error');
            $body = "Internal Server Error\n\n{$err}";
            $headers = Http\Headers::new()
                ->append('Content-Type', 'text/plain')
                ->append('Content-Length', (string)\strlen($body));
            $response = Http\Response::new($status, $headers, $body);
        }

        $status = $response->status;
        \header("{$status->protocol} {$status->code} {$status->message}");

        foreach ($response->headers as $header => $value) {
            \header("{$header}: {$value}");
        }

        echo $response->body;
    }
}
