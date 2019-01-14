<?php

namespace DDTrace\Tests\Integrations\Symfony\V3;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class TemplateEnginesTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
    }

    public function testAlternateTemplatingEngine()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Test alternate templating', '/alternate_templating'));
        });

        $this->assertSpans($traces, [
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
                    'http.url' => 'http://127.0.0.1:9999/alternate_templating',
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
                'Symfony\Component\Templating\PhpEngine php_template.template.php'
            ),
            SpanAssertion::exists('symfony.kernel.response'),
            SpanAssertion::exists('symfony.kernel.finish_request'),
            SpanAssertion::exists('symfony.kernel.terminate'),
        ]);
    }
}
