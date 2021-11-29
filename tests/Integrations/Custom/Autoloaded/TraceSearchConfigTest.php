<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class TraceSearchConfigTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_ANALYTICS_ENABLED' => 'true',
            'DD_WEB_ANALYTICS_SAMPLE_RATE' => '0.3',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing trace analytics config metric', '/simple'));
        });

        $this->assertExpectedSpans(
            $traces,
            [
                SpanAssertion::build(
                    'web.request',
                    'web.request',
                    'web',
                    'GET /simple'
                )->withExactTags([
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:' . self::PORT . '/simple',
                    'http.status_code' => '200',
                ])->withExactMetrics([
                    '_dd1.sr.eausr' => 0.3,
                    '_sampling_priority_v1' => 1,
                ]),
            ]
        );
    }
}
