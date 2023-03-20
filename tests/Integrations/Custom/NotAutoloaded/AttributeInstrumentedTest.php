<?php

namespace DDTrace\Tests\Integrations\Custom\NotAutoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class AttributeInstrumentedTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Not_Autoloaded/Attribute_Instrumentation/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_SERVICE' => 'my-service',
        ]);
    }

    public function testAttributeTracedSpanIsPresent()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = GetSpec::create(
                'Attribute Request',
                '/',
            );
            $this->call($spec);
        });

        $this->assertFlameGraph(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'my-service',
                    'web',
                    'GET /'
                )->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:' . self::PORT . '/',
                    'http.status_code' => 200,
                ])->withChildren([
                    SpanAssertion::build('traced', 'my-service', 'web', 'traced')->withExactTags([
                        'mode' => 'func',
                    ]),
                    SpanAssertion::build('TracedClass.func', 'my-service', 'web', 'TracedClass.func')->withExactTags([
                        'mode' => 'class',
                    ]),
                ]),
            ]
        );
    }
}
