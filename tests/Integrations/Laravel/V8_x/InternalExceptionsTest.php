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

    protected function getIntegrationName()
    {
        return ["laravel"];
    }

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
                        'http.url' => 'http://localhost:9999/not-implemented',
                        'http.status_code' => '501',
                        TAG::SPAN_KIND => 'server'
                    ])
                    ->withExactMetrics([
                        '_sampling_priority_v1' => 1,
                    ])
                    ->setError(
                        'Symfony\Component\HttpKernel\Exception\HttpException',
                        'Not Implemented',
                        true
                    )
                    ->withChildren([
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'not-implemented')
                            ->setError('Symfony\Component\HttpKernel\Exception\HttpException')
                            ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load'
                        ),
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
                        'http.url' => 'http://localhost:9999/unauthorized',
                        'http.status_code' => '403',
                        TAG::SPAN_KIND => 'server'
                    ])
                    ->withExactMetrics([
                        '_sampling_priority_v1' => 1,
                    ])
                    ->withChildren([
                        SpanAssertion::build(
                            'laravel.view.render',
                            'laravel_test_app',
                            'web',
                            'errors::403'
                        )->withChildren([
                            SpanAssertion::exists('laravel.view'),
                        ]),
                        SpanAssertion::build('laravel.action', 'laravel_test_app', 'web', 'unauthorized')
                            ->setError()
                            ->withExistingTagsNames([Tag::ERROR_MSG, 'error.stack']),
                        SpanAssertion::exists(
                            'laravel.provider.load',
                            'Illuminate\Foundation\ProviderRepository::load'
                        ),
                    ]),
            ]
        );
    }
}
