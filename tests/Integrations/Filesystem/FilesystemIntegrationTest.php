<?php

namespace DDTrace\Tests\Integrations\Filesystem;

use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;

final class FilesystemIntegrationTest extends IntegrationTestCase
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        IntegrationsLoader::load();
    }

    protected function setUp()
    {
        parent::setUp();
    }

    public function testFileGetAndPutContents()
    {
        $traces = $this->isolateTracer(function () {
            file_put_contents('test_file', 'contents');
            $this->assertEquals(file_get_contents('test_file'), 'contents');
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('filesystem.file_put_contents', 'filesystem', 'filesystem', 'test_file'),
            SpanAssertion::build('filesystem.file_get_contents', 'filesystem', 'filesystem', 'test_file'),
        ]);
    }
}
