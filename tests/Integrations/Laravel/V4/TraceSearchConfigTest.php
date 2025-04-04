<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class TraceSearchConfigTest extends WebFrameworkTestCase
{
    public static $database = "laravel42";

    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_ANALYTICS_ENABLED' => 'true',
            'DD_LARAVEL_ANALYTICS_SAMPLE_RATE' => '0.3',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing trace analytics config metric', '/simple'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build('laravel.request', 'laravel', 'web', 'HomeController@simple simple_route')
                    ->withExactTags([
                        'laravel.route.name' => 'simple_route',
                        'laravel.route.action' => 'HomeController@simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost/simple',
                        'http.status_code' => '200',
                        'http.route' => 'simple',
                        TAG::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'laravel',
                    ])
                    ->withExactMetrics([
                        '_dd1.sr.eausr' => 0.3,
                        '_sampling_priority_v1' => 1,
                        'process_id' => getmypid(),
                    ])
                    ->withChildren([
                        SpanAssertion::exists('laravel.application.handle')
                            ->withChildren([
                                SpanAssertion::build('laravel.action', 'laravel', 'web', 'simple')
                                ->withExactTags([
                                    Tag::COMPONENT => 'laravel',
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
            ]
        );
    }
}
