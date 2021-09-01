<?php

namespace DDTrace\Tests\Integrations\CodeIgniter\V2_2;

use DDTrace\Tag;
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
                        Tag::HTTP_URL => 'http://localhost:9999/simple',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Simple::index',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Simple.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Simple.index'
                        ),
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
                        Tag::HTTP_URL => 'http://localhost:9999/simple_view',
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Simple_View::index',
                    ])->withChildren([
                        SpanAssertion::build(
                            'Simple_View.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Simple_View.index'
                        )->withChildren([
                            SpanAssertion::build(
                                'CI_Loader.view',
                                'codeigniter_test_app',
                                Type::WEB_SERVLET,
                                'simple_view'
                            ),
                        ]),
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
                        Tag::HTTP_URL => 'http://localhost:9999/error',
                        // CodeIgniter's error handler does not adjust the status code
                        Tag::HTTP_STATUS_CODE => '200',
                        'app.endpoint' => 'Error_::index',
                    ])->ifPhpVersionNotMatch('5.4', function (SpanAssertion $assertion) {
                        // Automatic error attachment to root span in case of PHP 5.4 is still under development.
                        $message = PHP_MAJOR_VERSION >= 7
                            ? "Uncaught Exception: datadog in %s:%d"
                            : "Uncaught exception 'Exception' with message 'datadog' in %s:%d";
                        $assertion
                            ->setError(PHP_VERSION_ID >= 70000 ? "Exception" : "E_ERROR", $message)
                            ->withExistingTagsNames(['error.stack']);
                    })->withChildren([
                        SpanAssertion::build(
                            'Error_.index',
                            'codeigniter_test_app',
                            Type::WEB_SERVLET,
                            'Error_.index'
                        )->setError('Exception', 'datadog', true),
                    ]),
                ],
            ]
        );
    }
}
