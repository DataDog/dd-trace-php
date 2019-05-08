<?php

namespace DDTrace\Tests\Integrations\CLI\Laravel\V5_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Integrations\CLI\CLITestCase;

final class CommonScenariosTest extends CLITestCase
{
    protected function getScriptLocation()
    {
        return __DIR__ . '/../../../../Frameworks/Laravel/Version_5_8/artisan';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'artisan_test_app',
        ]);
    }

    public function testCommandWithNoArguments()
    {
        $traces = $this->isolateTracer(function () {
            $this->runCommand();
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'artisan',
                'artisan_test_app',
                'cli',
                'artisan'
            )->withExactTags([
                'integration.name' => 'laravel',
            ])
        ]);
    }

    /*
    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
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
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                        'integration.name' => 'laravel',
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
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                        'integration.name' => 'laravel',
                    ])->withExistingTagsNames(['laravel.route.name']),
                    SpanAssertion::build(
                        'laravel.view',
                        'laravel_test_app',
                        'web',
                        'laravel.view'
                    )->withExactTags([
                        'integration.name' => 'laravel',
                    ]),
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
                        'http.url' => 'http://localhost:9999/error',
                        'http.status_code' => '500',
                        'integration.name' => 'laravel',
                    ])->setError(),
                    SpanAssertion::exists('laravel.view')
                ],
            ]
        );
    }
    */
}
