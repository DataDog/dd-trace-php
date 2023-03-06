<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'custom_autoloaded_app',
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
        if (PHP_MAJOR_VERSION >= 8) {
            $additionalTags = [
                Tag::COMPONENT => 'lumen',
                Tag::SPAN_KIND => 'server'
            ];
        } else {
            $additionalTags = [];
        }

        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'web.request',
                        'web.request',
                        'web',
                        'GET /simple'
                    )->withExactTags(array_merge([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                    ], $additionalTags)),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'web.request',
                        'web.request',
                        'web',
                        'GET /simple_view'
                    )->withExactTags(array_merge([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                    ], $additionalTags)),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'web.request',
                        'web.request',
                        'web',
                        'GET /error'
                    )->withExactTags(array_merge([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/error?key=value&<redacted>',
                        'http.status_code' => '500',
                    ], $additionalTags))->setError(),
                ],
            ]
        );
    }
}
