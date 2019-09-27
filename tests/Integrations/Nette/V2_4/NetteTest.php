<?php

namespace DDTrace\Tests\Integrations\Nette\V2_4;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class NetteTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Nette/Version_2_4/www/index.php';
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
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
                        'nette.request',
                        'nette',
                        'web',
                        'Homepage:simple'
                    )
                        ->withExactTags([
                            'nette.route.presenter' => 'Homepage',
                            'nette.route.action' => 'simple',
                            'http.method' => 'GET',
                            'http.url' => '/simple',
                            'http.status_code' => '200',
                            'integration.name' => 'nette',
                        ]),
                    SpanAssertion::exists('nette.configurator.createRobotLoader'),
                    SpanAssertion::exists('nette.configurator.createContainer'),
                    SpanAssertion::exists('nette.application.run'),
                    SpanAssertion::exists('nette.router.match'),
                    SpanAssertion::exists('nette.presenter.run'),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::build(
                        'nette.request',
                        'nette',
                        'web',
                        'Homepage:simpleView'
                    )
                        ->withExactTags([
                            'nette.route.presenter' => 'Homepage',
                            'nette.route.action' => 'simpleView',
                            'http.method' => 'GET',
                            'http.url' => '/simple_view',
                            'http.status_code' => '200',
                            'integration.name' => 'nette',
                        ]),
                    SpanAssertion::exists('nette.configurator.createRobotLoader'),
                    SpanAssertion::exists('nette.configurator.createContainer'),
                    SpanAssertion::exists('nette.application.run'),
                    SpanAssertion::exists('nette.router.match'),
                    SpanAssertion::exists('nette.presenter.run'),
                    SpanAssertion::exists('nette.latte.render'),
                    SpanAssertion::exists('nette.latte.createTemplate'), // layout template
                    SpanAssertion::exists('nette.latte.createTemplate'), // simpleView template
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build(
                        'nette.request',
                        'nette',
                        'web',
                        'Homepage:errorView'
                    )->withExactTags([
                        'nette.route.presenter' => 'Homepage',
                        'nette.route.action' => 'errorView',
                        'http.method' => 'GET',
                        'http.url' => '/error',
                        'http.status_code' => '500',
                        'integration.name' => 'nette',
                    ])->setError('Exception', 'An exception occurred')
                        ->withExistingTagsNames(['error.stack']),

                    SpanAssertion::exists('nette.configurator.createRobotLoader'),
                    SpanAssertion::exists('nette.configurator.createContainer'),
                    SpanAssertion::exists('nette.application.run'),
                    SpanAssertion::exists('nette.router.match'),
                    SpanAssertion::exists('nette.presenter.run'),
                ],
            ]
        );
    }
}
