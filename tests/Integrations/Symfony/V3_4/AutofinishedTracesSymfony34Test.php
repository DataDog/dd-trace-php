<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class AutofinishedTracesSymfony34Test extends WebFrameworkTestCase
{
    const IS_SANDBOX = false;

    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_AUTOFINISH_SPANS' => 'true',
        ]);
    }

    public function testEndpointThatExitsWithNoProcess()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Endpoint that invoke exit()', '/terminated_by_exit'));
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build(
                'symfony.request',
                'symfony',
                'web',
                'terminated_by_exit'
            )
                ->withExactTags([
                    'symfony.route.action' => 'AppBundle\Controller\HomeController@actionBeingTerminatedByExit',
                    'symfony.route.name' => 'terminated_by_exit',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:9999/terminated_by_exit',
                    'http.status_code' => '200',
                    'integration.name' => 'symfony',
                ])
                ->withChildren([
                    SpanAssertion::exists('symfony.kernel.handle')
                        ->withChildren([
                            SpanAssertion::exists('symfony.kernel.request'),
                            SpanAssertion::exists('symfony.kernel.controller'),
                            SpanAssertion::exists('symfony.kernel.controller_arguments'),
                        ]),
                ]),
        ]);
    }
}
