<?php

namespace DDTrace\Tests\Integration\Frameworks\Laravel;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Frameworks\Util\ExpectationProvider;


class Laravel5ExpectationsProvider implements ExpectationProvider
{
    /**
     * @return SpanAssertion[]
     */
    public function provide()
    {
        return [
            'A simple GET request returning a string' => [
                SpanAssertion::build(
                    'laravel.request',
                    'laravel_test_app',
                    'web',
                    'App\Http\Controllers\CommonSpecsController@simple simple_route'
                )->withExactTags([
                    'laravel.route.name' => 'simple_route',
                    'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost/simple',
                    'http.status_code' => '200',
                ]),
            ],
            'A simple GET request with a view' => [
                SpanAssertion::build(
                    'laravel.request',
                    'laravel_test_app',
                    'web',
                    'App\Http\Controllers\CommonSpecsController@simple_view unnamed_route'
                )->withExactTags([
                    'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple_view',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost/simple_view',
                    'http.status_code' => '200',
                ])->withExistingTagsNames(['laravel.route.name']),
                SpanAssertion::build(
                    'laravel.view',
                    'laravel_test_app',
                    'web',
                    'laravel.view'
                ),
            ],
            'A GET request with an exception' => [
                SpanAssertion::build(
                    'laravel.request',
                    'laravel_test_app',
                    'web',
                    'App\Http\Controllers\CommonSpecsController@error unnamed_route'
                )->withExactTags([
                    'laravel.route.name' => '',
                    'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@error',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost/error',
                    'http.status_code' => '500',
                ]),
            ],
        ];
    }
}
