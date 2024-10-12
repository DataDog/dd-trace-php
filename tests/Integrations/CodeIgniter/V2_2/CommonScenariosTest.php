<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V2_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Type;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_2_2/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'codeigniter_test_app',
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

        $this->assertFlameGraph($traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/simple?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Simple::index',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'codeigniter',
                        Tag::HTTP_ROUTE => 'simple',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Simple.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Simple.index'
                        )->withExactTags([
                            Tag::COMPONENT => 'codeigniter',
                        ])
                    ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/simple_view?key=value&<redacted>',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Simple_View::index',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'codeigniter',
                        Tag::HTTP_ROUTE => 'simple_view',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Simple_View.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Simple_View.index'
                        )->withExactTags([
                            Tag::COMPONENT => 'codeigniter',
                        ])->withChildren([
                            SpanAssertion::build(
                                'CI_Loader.view',
                                'codeigniter_test_app',
                                Type::WEB_SERVLET,
                                'simple_view'
                            )->withExactTags([
                                Tag::COMPONENT => 'codeigniter',
                            ]),
                        ])
                    ]),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/error?key=value&<redacted>',
                        // CodeIgniter's error handler does not adjust the status code
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Error_::index',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'codeigniter',
                        Tag::HTTP_ROUTE => 'error'
                    ])
                    ->setError("Exception", "Uncaught Exception: datadog in %s:%d")
                    ->withExistingTagsNames(['error.stack'])
                    ->withChildren([
                        SpanAssertion::build(
                            'Error_.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Error_.index'
                        )->withExactTags([
                            Tag::COMPONENT => 'codeigniter',
                        ])->setError('Exception', 'datadog', true),
                    ]),
                ],
                'A GET request to a route with a parameter' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /parameterized/paramValue'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost/parameterized/paramValue',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Parameterized::customAction',
                        Tag::SPAN_KIND => 'server',
                        Tag::COMPONENT => 'codeigniter',
                        Tag::HTTP_ROUTE =>  'parameterized/(:any)',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Parameterized.customAction',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Parameterized.customAction'
                        )->withExactTags([
                            Tag::COMPONENT => 'codeigniter',
                        ])
                    ]),
                ],
            ]
        );
    }
}
