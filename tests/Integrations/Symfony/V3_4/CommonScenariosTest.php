<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
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

        $this->assertExpectedSpans($this, $traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'symfony.request',
                        'symfony',
                        'web',
                        'simple'
                    )
                        ->withExactTags([
                            'symfony.route.action' => 'AppBundle\Controller\CommonScenariosController@simpleAction',
                            'symfony.route.name' => 'simple',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:9999/simple',
                            'http.status_code' => '200',
                        ]),
                    SpanAssertion::exists('symfony.kernel.handle'),
                    SpanAssertion::exists('symfony.kernel.request'),
                    SpanAssertion::exists('symfony.kernel.controller'),
                    SpanAssertion::exists('symfony.kernel.controller_arguments'),
                    SpanAssertion::exists('symfony.kernel.response'),
                    SpanAssertion::exists('symfony.kernel.finish_request'),
                    SpanAssertion::exists('symfony.kernel.terminate'),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'symfony.request',
                        'symfony',
                        'web',
                        'simple_view'
                    )
                        ->withExactTags([
                            'symfony.route.action' => 'AppBundle\Controller\CommonScenariosController@simpleViewAction',
                            'symfony.route.name' => 'simple_view',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:9999/simple_view',
                            'http.status_code' => '200',
                        ]),
                    SpanAssertion::exists('symfony.kernel.handle'),
                    SpanAssertion::exists('symfony.kernel.request'),
                    SpanAssertion::exists('symfony.kernel.controller'),
                    SpanAssertion::exists('symfony.kernel.controller_arguments'),
                    SpanAssertion::build(
                        'symfony.templating.render',
                        'symfony',
                        'web',
                        'Twig_Environment twig_template.html.twig'
                    ),
                    SpanAssertion::exists('symfony.kernel.response'),
                    SpanAssertion::exists('symfony.kernel.finish_request'),
                    SpanAssertion::exists('symfony.kernel.terminate'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'symfony.request',
                        'symfony',
                        'web',
                        'error'
                    )
                        ->setError()
                        ->withExactTags([
                            'symfony.route.action' => 'AppBundle\Controller\CommonScenariosController@errorAction',
                            'symfony.route.name' => 'error',
                            'http.method' => 'GET',
                            'http.url' => 'http://localhost:9999/error',
                            'error.msg' => 'An exception occurred',
                            'error.type' => 'Exception',
                            'http.status_code' => '500',
                        ])
                        ->withExistingTagsNames(['error.stack']),
                    SpanAssertion::exists('symfony.kernel.handle'),
                    SpanAssertion::exists('symfony.kernel.request'),
                    SpanAssertion::exists('symfony.kernel.controller'),
                    SpanAssertion::exists('symfony.kernel.controller_arguments'),
                    SpanAssertion::exists('symfony.kernel.handleException'),
                    SpanAssertion::exists('symfony.kernel.exception'),
                    SpanAssertion::exists('symfony.templating.render'),
                    SpanAssertion::exists('symfony.kernel.response'),
                    SpanAssertion::exists('symfony.kernel.finish_request'),
                    SpanAssertion::exists('symfony.kernel.terminate'),
                ],
            ]
        );
    }
}
