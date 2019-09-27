<?php

namespace DDTrace\Tests\Integrations\Laravel\V4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosUriResourceNamesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_4_2/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'laravel_url_resources',
            'DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED' => 'true',
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

        $this->assertExpectedSpans($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_url_resources',
                        'web',
                        'GET /simple'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.action'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_url_resources',
                        'web',
                        'GET /simple_view'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.action'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.view.render'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'laravel.request',
                        'laravel_url_resources',
                        'web',
                        'GET /error'
                    )->withExactTags(SpanAssertion::NOT_TESTED)->setError(),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.event.handle'),
                    SpanAssertion::exists('laravel.action'),
                    SpanAssertion::exists('laravel.event.handle'),
                ],
            ]
        );
    }
}
