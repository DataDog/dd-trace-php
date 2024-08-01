<?php

namespace DDTrace\Tests\Integrations\Laravel\Octane;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    public static $iniPath;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Octane/artisan';
    }

    protected static function isOctane()
    {
        return true;
    }

    public static function ddSetUpBeforeClass()
    {
        $iniFiles = php_ini_scanned_files();
        $iniFile = explode(',', $iniFiles)[0];
        $iniDir = dirname($iniFile);
        self::$iniPath = $iniDir . '/swoole.ini';

        $swooleIni = file_get_contents(__DIR__ . '/swoole.ini');

        $currentDir = getcwd();
        $isLocalDevEnv = strpos($currentDir, 'datadog') === false;
        $replacement = $isLocalDevEnv ? '/home/circleci/app' : '/home/circleci/datadog';
        $swooleIni = str_replace('{{path}}', $replacement, $swooleIni);

        $autoloadNoCompile = getenv('DD_AUTOLOAD_NO_COMPILE');
        if (!$autoloadNoCompile || !filter_var($autoloadNoCompile, FILTER_VALIDATE_BOOLEAN)) {
            $swooleIni = str_replace('datadog.autoload_no_compile=true', 'datadog.autoload_no_compile=false', $swooleIni);
        }

        file_put_contents(self::$iniPath, $swooleIni);

        parent::ddSetUpBeforeClass();
    }

    public static function ddTearDownAfterClass()
    {
        parent::ddTearDownAfterClass();
        unlink(self::$iniPath);
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'swoole_test_app',
            'DD_TRACE_CLI_ENABLED' => 'true',
            'DD_TRACE_DEBUG' => 'true'
        ]);
    }

    public function testScenarioGetReturnString()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span
                        && isset($span["name"])
                        && $span["name"] === "laravel.request"
                        && (str_contains($span["resource"], 'App\\Http\\Controllers') || $span["resource"] === 'GET /does_not_exist')
                    ) {
                        return true;
                    }
                }
            }

            return false;
        };

        $traces = $this->tracesFromWebRequest(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request returning a string',
                    '/simple?key=value&pwd=should_redact'
                )
            );
        }, null, $until);

        $webRequestTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "laravel.request") {
                $webRequestTrace = $trace;
            }
        }

        $this->assertFlameGraph([$webRequestTrace], [
            SpanAssertion::build(
                'laravel.request',
                'swoole_test_app',
                'web',
                'App\Http\Controllers\CommonSpecsController@simple simple_route'
            )->withExactTags([
                Tag::HTTP_METHOD => 'GET',
                Tag::HTTP_URL => 'http://localhost/simple?key=value&<redacted>',
                Tag::HTTP_ROUTE => 'simple',
                Tag::HTTP_STATUS_CODE => '200',
                Tag::SPAN_KIND => 'server',
                Tag::COMPONENT => 'laravel',
                'laravel.route.name' => 'simple_route',
                'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple',
            ])->withChildren([
                SpanAssertion::build('laravel.action', 'swoole_test_app', 'web', 'simple')->withExactTags([
                    Tag::COMPONENT => 'laravel'
                ]),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
            ])
        ]);
    }

    public function testScenarioGetWithView()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span && isset($span["name"]) && $span["name"] === "laravel.request") {
                        return true;
                    }
                }
            }

            return false;
        };

        $traces = $this->tracesFromWebRequest(function () {
            $this->call(
                GetSpec::create(
                    'A simple GET request with a view',
                    '/simple_view?key=value&pwd=should_redact'
                )
            );
        }, null, $until);

        $webRequestTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "laravel.request") {
                $webRequestTrace = $trace;
            }
        }

        $this->assertFlameGraph([$webRequestTrace], [
            SpanAssertion::build(
                'laravel.request',
                'swoole_test_app',
                'web',
                'App\Http\Controllers\CommonSpecsController@simple_view unnamed_route'
            )->withExactTags([
                Tag::HTTP_METHOD => 'GET',
                Tag::HTTP_URL => 'http://localhost/simple_view?key=value&<redacted>',
                Tag::HTTP_ROUTE => 'simple_view',
                Tag::HTTP_STATUS_CODE => '200',
                Tag::SPAN_KIND => 'server',
                Tag::COMPONENT => 'laravel',
                'laravel.route.name' => 'unnamed_route',
                'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple_view',
            ])->withChildren([
                SpanAssertion::build('laravel.action', 'swoole_test_app', 'web', 'simple_view')->withExactTags([
                    Tag::COMPONENT => 'laravel'
                ]),
                SpanAssertion::build(
                    'laravel.view.render',
                    'swoole_test_app',
                    'web',
                    'simple_view'
                )->withExactTags([
                    Tag::COMPONENT => 'laravel',
                ])->withChildren([
                    SpanAssertion::build(
                        'laravel.view',
                        'swoole_test_app',
                        'web',
                        '*/resources/views/simple_view.blade.php'
                    )->withExactTags([
                        Tag::COMPONENT => 'laravel',
                    ])
                ]),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
            ])
        ]);
    }

    public function testScenarioGetWithException()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span && isset($span["name"]) && $span["name"] === "laravel.request") {
                        return true;
                    }
                }
            }

            return false;
        };

        $traces = $this->tracesFromWebRequest(function () {
            $this->call(
                GetSpec::create(
                    'A GET request with an exception',
                    '/error?key=value&pwd=should_redact'
                )
            );
        }, null, $until);

        $webRequestTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "laravel.request") {
                $webRequestTrace = $trace;
            }
        }

        $this->assertFlameGraph([$webRequestTrace], [
            SpanAssertion::build(
                'laravel.request',
                'swoole_test_app',
                'web',
                'App\Http\Controllers\CommonSpecsController@error unnamed_route'
            )->withExactTags([
                Tag::HTTP_METHOD => 'GET',
                Tag::HTTP_URL => 'http://localhost/error?key=value&<redacted>',
                Tag::HTTP_ROUTE => 'error',
                Tag::HTTP_STATUS_CODE => '500',
                Tag::SPAN_KIND => 'server',
                Tag::COMPONENT => 'laravel',
                'laravel.route.name' => 'unnamed_route',
                'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@error',
            ])->setError('Exception', 'Controller error', true)->withChildren([
                SpanAssertion::build('laravel.action', 'swoole_test_app', 'web', 'error')->withExactTags([
                    Tag::COMPONENT => 'laravel'
                ])->setError('Exception', 'Controller error', true),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
            ])
        ]);
    }

    public function testScenarioGetToMissingRoute()
    {
        $until = function ($request) {
            $body = $request["body"] ?? [];
            $traces = empty($body) ? [[]] : json_decode($body, true);

            foreach ($traces as $trace) {
                foreach ($trace as $span) {
                    if ($span && isset($span["name"]) && $span["name"] === "laravel.request") {
                        return true;
                    }
                }
            }

            return false;
        };

        $traces = $this->tracesFromWebRequest(function () {
            $this->call(
                GetSpec::create(
                    'A GET request to a missing route',
                    '/does_not_exist?key=value&pwd=should_redact'
                )
            );
        }, null, $until);

        $webRequestTrace = null;
        foreach ($traces as $trace) {
            if ($trace[0]["name"] === "laravel.request") {
                $webRequestTrace = $trace;
            }
        }

        $this->assertFlameGraph([$webRequestTrace], [
            SpanAssertion::build(
                'laravel.request',
                'swoole_test_app',
                'web',
                'GET /does_not_exist'
            )->withExactTags([
                Tag::HTTP_METHOD => 'GET',
                Tag::HTTP_URL => 'http://localhost/does_not_exist?key=value&<redacted>',
                Tag::HTTP_STATUS_CODE => '404',
                Tag::SPAN_KIND => 'server',
                Tag::COMPONENT => 'laravel',
            ])->withChildren([
                SpanAssertion::build(
                    'laravel.view.render',
                    'swoole_test_app',
                    'web',
                    'errors::404'
                )->withExactTags([
                    Tag::COMPONENT => 'laravel',
                ])->withChildren([
                    SpanAssertion::build(
                        'laravel.view',
                        'swoole_test_app',
                        'web',
                        '*/views/404.blade.php'
                    )->withExactTags([
                        Tag::COMPONENT => 'laravel',
                    ])
                ]),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
                SpanAssertion::exists('laravel.event.handle', null, null, 'swoole_test_app'),
            ])
        ]);
    }
}
