<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tag;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

class AutofinishedTracesSymfony34Test extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/index.php';
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
            )->withExactTags([
                'symfony.route.action' => 'AppBundle\Controller\HomeController@actionBeingTerminatedByExit',
                'symfony.route.name' => 'terminated_by_exit',
                'http.method' => 'GET',
                'http.url' => 'http://localhost/terminated_by_exit',
                'http.status_code' => '200',
                Tag::SPAN_KIND => 'server',
                Tag::COMPONENT => 'symfony',
            ])->withChildren([
                SpanAssertion::exists('symfony.httpkernel.kernel.handle')->withChildren([
                    SpanAssertion::exists('symfony.httpkernel.kernel.boot'),
                    SpanAssertion::exists('symfony.kernel.handle')->withChildren([
                        SpanAssertion::exists('symfony.kernel.request')->withChildren([
                            SpanAssertion::exists('symfony.security.authentication.success'),
                        ]),
                        SpanAssertion::exists('symfony.kernel.controller'),
                        SpanAssertion::exists('symfony.kernel.controller_arguments'),
                        SpanAssertion::build(
                            'symfony.controller',
                            'symfony',
                            'web',
                            'AppBundle\Controller\HomeController::actionBeingTerminatedByExit'
                        )->withExactTags([
                            Tag::COMPONENT => 'symfony',
                        ]),
                    ]),
                ]),
            ]),
        ]);
    }
}
