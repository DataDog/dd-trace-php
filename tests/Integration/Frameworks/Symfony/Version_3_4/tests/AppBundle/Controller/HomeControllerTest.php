<?php

namespace Tests\AppBundle\Controller;

use DDTrace\Tests\Integration\Common\SpanAssertion;
use DDTrace\Tests\Integration\Common\SpanAssertionTrait;
use DDTrace\Tests\Integration\Common\TracerTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function testAlternateTemplatingEngine()
    {
        $client = static::createClient();
        $traces = $this->simulateWebRequestTracer(function() use ($client) {
            $crawler = $client->request('GET', '/alternate_templating');
            $response = $client->getResponse();

            $this->assertSame(200, $response->getStatusCode());
        });

        $this->assertExpectedSpans($this, $traces, [
            SpanAssertion::build(
                'symfony.request',
                'symfony',
                'web',
                'simple_view'
            )
                ->withExactTags([
                    'symfony.route.action' => 'AppBundle\Controller\CommonScenariosController@simpleViewAction',
                    'symfony.route.name' => 'simple_view',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost/simple_view',
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
                'Twig_Environment twig_template.html.twig'
            ),
            SpanAssertion::exists('symfony.kernel.response'),
            SpanAssertion::exists('symfony.kernel.finish_request'),
            SpanAssertion::exists('symfony.kernel.terminate'),
        ]);
    }
}
