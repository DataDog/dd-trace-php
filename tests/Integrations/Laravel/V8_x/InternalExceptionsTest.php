<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class InternalExceptionsTest extends WebFrameworkTestCase
{
    use TracerTestTrait;
    use SpanAssertionTrait;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'laravel_test_app',
            'DD_TRACE_DEBUG' => '1',
        ]);
    }

    public function testNotImplemented()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Test internal exceptions are not reported', '/not-implemented'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'laravel.request',
                    'laravel_test_app',
                    'web',
                    'App\Http\Controllers\InternalErrorController@notImplemented not-implemented'
                )
                    ->withExactTags([
                        'laravel.route.name' => 'not-implemented',
                        'laravel.route.action' => 'App\Http\Controllers\InternalErrorController@notImplemented',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/not-implemented',
                        'http.status_code' => '501',
                        'http.route' => 'not-implemented',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])
                    ->withExactMetrics([
                        '_sampling_priority_v1' => 1,
                        'process_id' => getmypid(),
                    ])
                    ->withChildren([
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'not-implemented')
                            ->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ])
                            ->setError('Symfony\Component\HttpKernel\Exception\HttpException')
                            ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load'
                        ),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                    ]),
            ]
        );
    }


    public function testUnauthorized()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Test unauthorized is not an error', '/unauthorized'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'laravel.request',
                    'laravel_test_app',
                    'web',
                    'App\Http\Controllers\InternalErrorController@unauthorized unauthorized'
                )
                    ->withExactTags([
                        'laravel.route.name' => 'unauthorized',
                        'laravel.route.action' => 'App\Http\Controllers\InternalErrorController@unauthorized',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/unauthorized',
                        'http.status_code' => '403',
                        'http.route' => 'unauthorized',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel'
                    ])
                    ->withExactMetrics([
                        '_sampling_priority_v1' => 1,
                        'process_id' => getmypid(),
                    ])
                    ->withChildren([
                        SpanAssertion::build(
                            'laravel.view.render',
                            'laravel_test_app',
                            'web',
                            'errors::403'
                        )->withExactTags([
                            TAG::COMPONENT => 'laravel'
                        ])->withChildren([
                            SpanAssertion::exists('laravel.view')->withChildren([
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                            ]),
                            SpanAssertion::exists('laravel.event.handle'),
                        ]),
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'unauthorized')
                            ->withExactTags([
                                TAG::COMPONENT => 'laravel'
                            ])
                            ->setError()
                            ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load'
                        ),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                        SpanAssertion::exists('laravel.event.handle'),
                    ]),
            ]
        );
    }
}
