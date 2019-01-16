<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace\Configuration;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tag;
use DDTrace\Time;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use DDTrace\GlobalTracer;

/**
 * DataDog Laravel tracing provider. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then load the provider in config/app.php:
 *
 *     'providers' => array_merge(include(base_path('modules/system/providers.php')), [
 *        // 'Illuminate\Html\HtmlServiceProvider', // Example
 *
 *        'DDTrace\Integrations\LaravelProvider',
 *        'System\ServiceProvider',
 *   ]),
 */
class LaravelProvider extends ServiceProvider
{
    const NAME = 'laravel';

    /**  @inheritdoc */
    public function register()
    {
    }

    /**  @inheritdoc */
    public function boot()
    {
    }
}
