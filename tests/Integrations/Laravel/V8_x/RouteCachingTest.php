<?php

namespace DDTrace\Tests\Integrations\Laravel\V8_x;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class RouteCachingTest extends WebFrameworkTestCase
{
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
                    ])
                    ->withChildren([
                        SpanAssertion::exists('laravel.action'),
                        SpanAssertion::exists('laravel.provider.load'),
                    ]),
            ]
        );
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
