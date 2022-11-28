<?php

namespace DDTrace\Tests\Integrations\Roadrunner\V2;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Roadrunner/Version_2/worker.php';
    }

    protected static function getRoadrunnerVersion()
    {
        return "2.11.4";
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

        $this->assertFlameGraph($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'web.request',
                        'roadrunner',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/simple?key=value&<redacted>',
                        'http.status_code' => '200',
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'web.request',
                        'roadrunner',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/simple_view?key=value&<redacted>',
                        'http.status_code' => '200',
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'web.request',
                        'roadrunner',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:' . self::PORT . '/error?key=value&<redacted>',
                        'http.status_code' => '500',
                        'error.stack' => '#0 {main}',
                    ])->setError('Exception', 'Uncaught Exception: Error page'),
                ],
            ]
        );
    }
}
