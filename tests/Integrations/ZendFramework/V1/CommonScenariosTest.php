<?php

namespace DDTrace\Tests\Integrations\ZendFramework\V1;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

final class CommonScenariosTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/ZendFramework/Version_1_12/public/index.php';
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

        $this->assertExpectedSpans($this, $traces, $spanExpectations);
    }

    public function provideSpecs()
    {
        return $this->buildDataProvider(
            [
                'A simple GET request returning a string' => [
                    SpanAssertion::build('zf1.request', 'zf1', 'web', 'zf1.request')
                        ->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => 'http://127.0.0.1:9999/simple',
                        ]),
                ],
                'A simple GET request with a view' => [
                    SpanAssertion::exists('zf1.request'),
                ],
                'A GET request with an exception' => [
                    SpanAssertion::build('zf1.request', 'zf1', 'web', 'zf1.request')
                        ->withExactTags([
                            'http.method' => 'GET',
                            'http.url' => 'http://127.0.0.1:9999/error',
                        ]),
                ],
            ]
        );
    }
}
