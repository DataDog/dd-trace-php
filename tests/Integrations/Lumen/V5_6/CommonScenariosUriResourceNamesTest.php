<?php

namespace DDTrace\Tests\Integrations\Lumen\V5_6;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosUriResourceNamesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Lumen/Version_5_6/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_APP_NAME' => 'lumen_uri_resource_names',
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
                        'lumen.request',
                        'lumen_uri_resource_names',
                        'web',
                        'GET /simple'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'lumen.request',
                        'lumen_uri_resource_names',
                        'web',
                        'GET /simple_view'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                    SpanAssertion::exists('lumen.view'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'lumen.request',
                        'lumen_uri_resource_names',
                        'web',
                        'GET /error'
                    )->withExactTags(SpanAssertion::NOT_TESTED)->setError(),
                ],
            ]
        );
    }
}
