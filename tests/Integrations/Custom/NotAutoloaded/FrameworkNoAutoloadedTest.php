<?php

namespace DDTrace\Tests\Integrations\Custom\NotAutoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class FrameworkNoAutoloadedTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Not_Autoloaded/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'APP_NAME' => 'custom_no_autoloaded_app',
            'DD_TRACE_NO_AUTOLOADER' => 'true',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testTracingActivatedByEnvVariable()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('A web request to a framework not using auto loaders', '/'));
        });

        $this->assertSpans($traces, [
            SpanAssertion::exists('web.request'),
        ]);
    }
}
