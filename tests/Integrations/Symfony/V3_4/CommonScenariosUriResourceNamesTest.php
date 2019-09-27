<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosUriResourceNamesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE_NAME' => 'symfony_uri_resource_names',
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
                        'symfony.request',
                        'symfony_uri_resource_names',
                        'web',
                        'GET /simple'
                    )
                        ->withExactTags(SpanAssertion::NOT_TESTED),
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
                        'symfony_uri_resource_names',
                        'web',
                        'GET /simple_view'
                    )
                        ->withExactTags(SpanAssertion::NOT_TESTED),
                    SpanAssertion::exists('symfony.kernel.handle'),
                    SpanAssertion::exists('symfony.kernel.request'),
                    SpanAssertion::exists('symfony.kernel.controller'),
                    SpanAssertion::exists('symfony.kernel.controller_arguments'),
                    SpanAssertion::exists('symfony.templating.render'),
                    SpanAssertion::exists('symfony.kernel.response'),
                    SpanAssertion::exists('symfony.kernel.finish_request'),
                    SpanAssertion::exists('symfony.kernel.terminate'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'symfony.request',
                        'symfony_uri_resource_names',
                        'web',
                        'GET /error'
                    )
                        ->withExactTags(SpanAssertion::NOT_TESTED)->setError(),
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
