<?php

namespace DDTrace\Tests\Integrations\Symfony\V4_4;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class SwallowedExceptionTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_4_4/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'test_symfony_44',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testSwallowedException()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Swallowed exception regression', '/notSwallowed'));
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'symfony.request',
                    'test_symfony_44',
                    'web',
                    'notSwallowed'
                )->withExactTags([
                    'symfony.route.action' => 'App\Controller\DefaultController@index',
                    'symfony.route.name' => 'notSwallowed',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:9999/notSwallowed',
                    'http.status_code' => '500',
                    Tag::SPAN_KIND => 'server',
                    Tag::COMPONENT => 'symfony',
                ])
                    ->setError('Error', 'Call to a member function hello() on null')
                    ->withExistingTagsNames(['error.stack'])
                    ->withChildren([
                        SpanAssertion::exists('symfony.kernel.terminate'),
                        SpanAssertion::exists('symfony.httpkernel.kernel.handle')
                            ->setError('Error', 'Call to a member function hello() on null')
                            ->withExistingTagsNames(['error.stack'])
                            ->withChildren([
                                SpanAssertion::exists('symfony.httpkernel.kernel.boot')
                                    ->setError('Error', 'Call to a member function hello() on null')
                                    ->withExistingTagsNames(['error.stack']),
                                SpanAssertion::exists('symfony.kernel.handle')->withChildren([
                                    SpanAssertion::exists('symfony.kernel.request'),
                                    SpanAssertion::exists('symfony.kernel.controller'),
                                    SpanAssertion::exists('symfony.kernel.controller_arguments'),
                                    SpanAssertion::build(
                                        'symfony.controller',
                                        'test_symfony_44',
                                        'web',
                                        'App\Controller\DefaultController::index'
                                    )->withExactTags([
                                        Tag::COMPONENT => 'symfony',
                                    ])
                                        ->setError('Error', 'Call to a member function hello() on null')
                                        ->withExistingTagsNames(['error.stack']),
                                ]),
                            ]),
                        SpanAssertion::exists('symfony.kernel.handleException')
                            ->withChildren([
                                SpanAssertion::exists('symfony.kernel.finish_request'),
                                SpanAssertion::exists('symfony.kernel.response')->withChildren([
                                    SpanAssertion::exists('symfony.templating.render')
                                ]),
                                SpanAssertion::exists('symfony.kernel.exception')->withChildren([
                                    SpanAssertion::exists('symfony.kernel.handle')->withChildren([
                                        SpanAssertion::exists('symfony.controller'),
                                        SpanAssertion::exists('symfony.kernel.controller'),
                                        SpanAssertion::exists('symfony.kernel.controller_arguments'),
                                        SpanAssertion::exists('symfony.kernel.finish_request'),
                                        SpanAssertion::exists('symfony.kernel.request'),
                                        SpanAssertion::exists('symfony.kernel.response')->withChildren([
                                            SpanAssertion::exists('symfony.security.authentication.success')
                                        ]),
                                    ])
                                ]),
                            ])
                    ])
            ]
        );
    }
}
