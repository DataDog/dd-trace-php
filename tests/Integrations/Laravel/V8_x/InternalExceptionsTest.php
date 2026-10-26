<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class InternalExceptionsTest extends WebFrameworkTestCase
{
    public static $database = "laravel8";

    public static function getAppIndexScript()
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
                    ->withExactAttributes([
                        'laravel.route.name' => 'not-implemented',
                        'laravel.route.action' => 'App\Http\Controllers\InternalErrorController@notImplemented',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/not-implemented',
                        'http.status_code' => '501',
                        'http.route' => 'not-implemented',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel',
                        '_dd.svc_src' => 'laravel',
                    ])
                    ->samplingPriority(1)
                    ->withChildren([
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'not-implemented')
                            ->withExactAttributes([
                                TAG::COMPONENT => 'laravel',
                                '_dd.svc_src' => 'laravel',
                                'track_error' => 'false',
                            ])
                            ->setError('Symfony\Component\HttpKernel\Exception\HttpException')
                            ->withExistingAttributeNames([Tag::ERROR_MSG, 'error.stack']),
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
                    ->withExactAttributes([
                        'laravel.route.name' => 'unauthorized',
                        'laravel.route.action' => 'App\Http\Controllers\InternalErrorController@unauthorized',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/unauthorized',
                        'http.status_code' => '403',
                        'http.route' => 'unauthorized',
                        TAG::SPAN_KIND => 'server',
                        TAG::COMPONENT => 'laravel',
                        '_dd.svc_src' => 'laravel',
                    ])
                    ->samplingPriority(1)
                    ->withChildren([
                        SpanAssertion::build(
                            'laravel.view.render',
                            'laravel_test_app',
                            'web',
                            'errors::403'
                        )->withExactAttributes([
                            TAG::COMPONENT => 'laravel',
                            '_dd.svc_src' => 'laravel',
                        ])->withChildren([
                            SpanAssertion::exists('laravel.view')->withChildren([
                                SpanAssertion::exists('laravel.event.handle'),
                                SpanAssertion::exists('laravel.event.handle'),
                            ]),
                            SpanAssertion::exists('laravel.event.handle'),
                        ]),
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'unauthorized')
                            ->withExactAttributes([
                                TAG::COMPONENT => 'laravel',
                                '_dd.svc_src' => 'laravel',
                                'track_error' => 'false',
                            ])
                            ->setError()
                            ->withExistingAttributeNames([Tag::ERROR_MSG, 'error.stack']),
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
