<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V2_2;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;
use DDTrace\Type;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    const IS_SANDBOX = true;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CodeIgniter/Version_2_2/ddshim.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'codeigniter_test_app',
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
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /simple'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost:9999/simple',
                        Tag::HTTP_STATUS_CODE => '200',
                        'integration.name' => 'codeigniter',
                        'app.endpoint' => 'Simple::index',
                    ]),
                    SpanAssertion::build(
                        'Simple.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Simple.index'
                    ),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /simple_view'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost:9999/simple_view',
                        Tag::HTTP_STATUS_CODE => '200',
                        'integration.name' => 'codeigniter',
                        'app.endpoint' => 'Simple_View::index',
                    ]),
                    SpanAssertion::build(
                        'Simple_View.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Simple_View.index'
                    ),
                    SpanAssertion::build(
                        'CI_Loader.view',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'simple_view'
                    ),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /error'
                    )->withExactTags([
                        Tag::HTTP_METHOD => 'GET',
                        Tag::HTTP_URL => 'http://localhost:9999/error',
                        // CodeIgniter's error handler does not adjust the status code
                        Tag::HTTP_STATUS_CODE => '200',
                        'integration.name' => 'codeigniter',
                        'app.endpoint' => 'Error_::index',
                    ]),
                    SpanAssertion::build(
                        'Error_.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Error_.index'
                    )->setError('Exception', 'datadog', true),
                ],
                'A GET request ended in http_status_code(200)' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /http_response_code_success'
                    )->withExactTags(
                        [
                            Tag::HTTP_METHOD      => 'GET',
                            Tag::HTTP_URL         => 'http://localhost:9999/http_response_code_success',
                            // CodeIgniter's error handler does not adjust the status code
                            Tag::HTTP_STATUS_CODE => '200',
                            'integration.name'    => 'codeigniter',
                            'app.endpoint'        => 'Simple::http_response_code_success',
                        ]
                    ),
                    SpanAssertion::build(
                        'Error_.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Simple.http_response_code_success'
                    ),
                'A GET request ended in http_status_code(500)' => [
                    SpanAssertion::build(
                        'codeigniter.request',
                        'codeigniter_test_app',
                        'web',
                        'GET /error/http_response_code_failure'
                    )->withExactTags(
                        [
                            Tag::HTTP_METHOD      => 'GET',
                            Tag::HTTP_URL         => 'http://localhost:9999/error/http_response_code_failure',
                            // CodeIgniter's error handler does not adjust the status code
                            Tag::HTTP_STATUS_CODE => '200',
                            'integration.name'    => 'codeigniter',
                            'app.endpoint'        => 'Error_::http_response_code_failure',
                        ]
                    ),
                    SpanAssertion::build(
                        'Error_.index',
                        'codeigniter_test_app',
                        Type::WEB_SERVLET,
                        'Error_.http_response_code_failure'
                    )->setError('Exception', 'datadog', true),
                ]
            ]
        );
    }
}
