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
                        'http.status_code' => '200',
                    ]),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple'),
                SpanAssertion::exists('laravel.event.handle'),
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
            'A GET request with an exception' => [
                SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@error error')
                    ->withExactTags([
                        'laravel.route.name' => 'error',
                        'laravel.route.action' => 'HomeController@error',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/error',
                        'http.status_code' => '500'
                    ]),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::exists('laravel.event.handle'),
                SpanAssertion::build('laravel.action', 'laravel', 'web', 'laravel.action')
                    ->withExactTags([
                        'error.msg' => 'Controller error',
                        'error.type' => 'Exception',
                    ])
                    ->withExistingTagsNames(['error.stack'])
                    ->setError(),
                SpanAssertion::exists('laravel.event.handle'),
            ],
        ];
    }
}
