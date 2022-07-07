<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_DEBUG' => 'true',
            'DD_TAGS' => 'some.key1:value,some.key2:value2',
        ]);
    }

    /**
     * @dataProvider provideSpecs
     * @param RequestSpec $spec
     * @param array $spanExpectations
     * @throws \Exception
     */
    public function testScenario(RequestSpec $spec, array $spanExpectations)
    {
        $traces = $this->tracesFromWebRequest(function () use ($spec) {
            $this->call($spec);
        });

        $this->assertFlameGraph($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@simple simple_route')
                        ->withExactTags([
                            'laravel.route.name' => 'simple_route',
                            'laravel.route.action' => 'HomeController@simple',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:9999/simple?key=value&<redacted>',
                            'http.status_code' => '200',
                            'some.key1' => 'value',
                            'some.key2' => 'value2',
                        ])
                        ->withChildren([
                            SpanAssertion::exists('laravel.application.handle')
                                ->withChildren([
                                    SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple')
                                        ->withExactTags([
                                            'some.key1' => 'value',
                                            'some.key2' => 'value2',
                                        ]),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                ]),
                            SpanAssertion::exists(
                                'laravel.provider.load',
                                'Illuminate\Foundation\ProviderRepository::load'
                            )->withChildren([
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                            ]),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                        ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::exists('laravel.request')
                        ->withChildren([
                            SpanAssertion::exists('laravel.application.handle')
                                ->withChildren([
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.action')
                                        ->withChildren([
                                            SpanAssertion::exists('laravel.event.handle'),
                                        ]),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::build('laravel.view.render', 'laravel', 'web', 'simple_view')
                                        ->withExactTags([
                                            'some.key1' => 'value',
                                            'some.key2' => 'value2',
                                        ])
                                        ->withChildren([
                                            SpanAssertion::exists('laravel.event.handle'),
                                        ]),
                                ]),
                            SpanAssertion::exists(
                                'laravel.provider.load',
                                'Illuminate\Foundation\ProviderRepository::load'
                            )->withChildren([
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                            ]),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                        ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@error error')
                        ->withExactTags([
                            'laravel.route.name' => 'error',
                            'laravel.route.action' => 'HomeController@error',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:9999/error?key=value&<redacted>',
                            'http.status_code' => '500',
                            'some.key1' => 'value',
                            'some.key2' => 'value2',
                        ])->setError()->withChildren([
                            SpanAssertion::exists('laravel.application.handle')
                                ->withChildren([
                                    SpanAssertion::build('laravel.action', 'laravel', 'web', 'error')
                                        ->withExactTags([
                                            'some.key1' => 'value',
                                            'some.key2' => 'value2',
                                        ])
                                        ->withExistingTagsNames(['error.stack'])
                                        ->setError('Exception', 'Controller error'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                    SpanAssertion::exists('laravel.event.handle'),
                                ]),
                            SpanAssertion::exists(
                                'laravel.provider.load',
                                'Illuminate\Foundation\ProviderRepository::load'
                            )->withChildren([
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                            ]),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                            SpanAssertion::exists('laravel.event.handle'),
                        ]),
                ],
            ]
        );
    }
}
