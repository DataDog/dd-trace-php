<?php

namespace DDTrace\Tests\Integrations\Slim\V3_12;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Slim/Version_3_12/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'slim_test_app',
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
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET simple-route'
                    )->withExactTags([
                        'slim.route.controller' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                        'integration.name' => 'slim',
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        'slim.route.controller' => 'App\SimpleViewController::index',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple_view',
                        'http.status_code' => '200',
                        'integration.name' => 'slim',
                    ]),
                    SpanAssertion::build(
                        'slim.view',
                        'slim_test_app',
                        'web',
                        'simple_view.phtml'
                    )->withExactTags([
                        'slim.view' => 'simple_view.phtml',
                        'integration.name' => 'slim',
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'slim.request',
                        'slim_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        'slim.route.controller' => 'Closure::__invoke',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/error',
                        'http.status_code' => '500',
                        'integration.name' => 'slim',
                    ])->withExistingTagsNames([
                        'error.stack'
                    ])->setError(null, 'Foo error'),
                ],
            ]
        );
    }
}
