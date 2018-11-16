<?php

namespace DDTrace\Tests\Integration\Frameworks\Laravel;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Frameworks\Util\ExpectationProvider;


class Laravel4ExpectationsProvider implements ExpectationProvider
{
    /**
     * @return SpanAssertion[]
     */
    public function provide()
    {
        return [
            'A simple GET request returning a string' => [
                SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@simple simple_route')
                    ->withExactTags([
                        'laravel.route.name' => 'simple_route',
                        'laravel.route.action' => 'HomeController@simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple',
                    ]),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple'),
            ],
            'A simple GET request with a view' => [
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.action'),
                SpanAssertion::build('laravel.view.render', 'laravel', 'web', 'simple_view'),
                SpanAssertion::exists('laravel.request'),
            ],
        ];
    }
}
