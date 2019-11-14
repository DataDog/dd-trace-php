<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class TemplateEnginesTest extends WebFrameworkTestCase
{
    const IS_SANDBOX = false;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
    }

    public function testAlternateTemplatingEngine()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Test alternate templating', '/alternate_templating'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'symfony.request',
                'symfony',
                'web',
                'alternate_templating'
            )
                ->withExactTags([
                    'symfony.route.action' => 'AppBundle\Controller\HomeController@indexAction',
                    'symfony.route.name' => 'alternate_templating',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:9999/alternate_templating',
                    'http.status_code' => '200',
                    'integration.name' => 'symfony',
                ])
                ->withChildren([
                    SpanAssertion::exists('symfony.kernel.handle')
                        ->withChildren([
                            SpanAssertion::exists('symfony.kernel.request'),
                            SpanAssertion::exists('symfony.kernel.controller'),
                            SpanAssertion::exists('symfony.kernel.controller_arguments'),
                            SpanAssertion::build(
                                'symfony.templating.render',
                                'symfony',
                                'web',
                                'Symfony\Component\Templating\PhpEngine php_template.template.php'
                            )
                                ->withExactTags([
                                    'integration.name' => 'symfony',
                                ]),
                            SpanAssertion::exists('symfony.kernel.response'),
                            SpanAssertion::exists('symfony.kernel.finish_request'),
                        ]),
                    SpanAssertion::exists('symfony.kernel.terminate'),
                ]),
        ]);
    }
}
