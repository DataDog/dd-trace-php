<?php

namespace DDTrace\Tests\Integrations\CakePHP\V2_8;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosUriResourceNamesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/CakePHP/Version_2_8/app/webroot/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'cakephp_uri_resource_names',
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
                        'cakephp.request',
                        'cakephp_uri_resource_names',
                        'web',
                        'GET /simple'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_uri_resource_names',
                        'web',
                        'GET /simple_view'
                    )->withExactTags(SpanAssertion::NOT_TESTED),
                    SpanAssertion::exists('cakephp.view'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'cakephp.request',
                        'cakephp_uri_resource_names',
                        'web',
                        'GET /error'
                    )
                        ->withExactTags(SpanAssertion::NOT_TESTED)
                        ->setError(),
                    SpanAssertion::exists('cakephp.view'),
                ],
            ]
        );
    }
}
