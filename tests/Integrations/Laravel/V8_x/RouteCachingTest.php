<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tag;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class RouteCachingTest extends WebFrameworkTestCase
{
    protected function getIntegrationName()
    {
        return ["laravel"];
    }

    protected static function getIntegrationNameStatic()
    {
        return ["laravel"];
    }
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_8_x/public/index.php';
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        $this->routeClear();
    }

    public function testNotCached()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing route caching: uncached', '/unnamed-route'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'laravel.request',
                    'Laravel',
                    'web',
                    'App\Http\Controllers\RouteCachingController@unnamed unnamed_route'
                )
                    ->withExactTags([
                        'laravel.route.name' => 'unnamed_route',
                        'laravel.route.action' => 'App\Http\Controllers\RouteCachingController@unnamed',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/unnamed-route',
                        'http.status_code' => '200',
                        TAG::SPAN_KIND => 'server'
                    ])
                    ->withChildren([
                        SpanAssertion::exists('laravel.action'),
                        SpanAssertion::exists('laravel.provider.load'),
                    ]),
            ]
        );
    }

    public function testCached()
    {
        $this->routeCache();
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing route caching: uncached', '/unnamed-route'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'laravel.request',
                    'Laravel',
                    'web',
                    'App\Http\Controllers\RouteCachingController@unnamed unnamed_route'
                )
                    ->withExactTags([
                        'laravel.route.name' => 'unnamed_route',
                        'laravel.route.action' => 'App\Http\Controllers\RouteCachingController@unnamed',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/unnamed-route',
                        'http.status_code' => '200',
                        TAG::SPAN_KIND => 'server'
                    ])
                    ->withChildren([
                        SpanAssertion::exists('laravel.action'),
                        SpanAssertion::exists('laravel.provider.load'),
                    ]),
            ]
        );
    }

    public function testRouteNameNormalization()
    {
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName(null));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName(''));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName('     '));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName(123));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName(true));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName([]));
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName(new \stdClass()));
        // Laravel cached route names after version 7
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName('generated::abcdef0912'));
        // Laravel cached route when Route::domain('domain.com')->group(...)
        $this->assertSame('unnamed_route', LaravelIntegration::normalizeRouteName('domain.com.generated::abcdef0912'));

        $this->assertSame('my_route', LaravelIntegration::normalizeRouteName('my_route'));
    }

    private function routeCache()
    {
        $appRoot = \dirname(\dirname(self::getAppIndexScript()));
        `cd $appRoot && php artisan route:cache`;
    }

    private function routeClear()
    {
        $appRoot = \dirname(\dirname(self::getAppIndexScript()));
        `cd $appRoot && php artisan route:clear`;
    }
}
