<?php

namespace DDTrace\Tests\Integrations\Custom\Autoloaded;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\RequestSpec;

class ProcessTagsWebTest extends WebFrameworkTestCase
{
    public static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Custom/Version_Autoloaded/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED' => 'true',
            'DD_TRACE_GENERATE_ROOT_SPAN' => '1',
            'DD_TRACE_AUTO_FLUSH_ENABLED' => '1',
        ]);
    }

    public function testProcessTagsEnabledForWebSapi()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $spec = new RequestSpec(
                __FUNCTION__,
                'GET',
                '/simple',
                []
            );
            return $this->call($spec);
        });

        $this->assertCount(1, $traces);
        $rootSpan = $traces[0][0];

        // Verify _dd.tags.process exists
        $this->assertArrayHasKey('_dd.tags.process', $rootSpan['meta']);
        $processTags = $rootSpan['meta']['_dd.tags.process'];

        // Parse the process tags
        $tags = [];
        foreach (explode(',', $processTags) as $pair) {
            [$key, $value] = explode(':', $pair, 2);
            $tags[$key] = $value;
        }

        // Web SAPI should have server.type and entrypoint.workdir
        $this->assertArrayHasKey('server.type', $tags, 'server.type should be present for web SAPI');
        $this->assertArrayHasKey('entrypoint.workdir', $tags, 'entrypoint.workdir should be present');

        // Web SAPI should NOT have entrypoint.name, entrypoint.basedir, or entrypoint.type
        $this->assertArrayNotHasKey('entrypoint.name', $tags, 'entrypoint.name should not be present for web SAPI');
        $this->assertArrayNotHasKey('entrypoint.basedir', $tags, 'entrypoint.basedir should not be present for web SAPI');
        $this->assertArrayNotHasKey('entrypoint.type', $tags, 'entrypoint.type should not be present for web SAPI');

        // Verify server.type is one of the expected SAPIs tested in CI
        $expectedSapis = ['cli-server', 'cgi-fcgi', 'apache2handler', 'fpm-fcgi'];
        $this->assertContains(
            $tags['server.type'],
            $expectedSapis,
            sprintf(
                'server.type should be one of [%s], got: %s',
                implode(', ', $expectedSapis),
                $tags['server.type']
            )
        );

        // Verify server.type is normalized (lowercase, only allowed chars)
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9\/_.-]+$/',
            $tags['server.type'],
            'server.type should be normalized'
        );
    }
}

