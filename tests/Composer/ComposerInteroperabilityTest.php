<?php

namespace DDTrace\Tests\Composer;

use DDTrace\Tests\Common\TracerTestTrait;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;
use DDTrace\Tests\Common\BaseTestCase;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_AssertionFailedError;

class ComposerInteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait;

    public function testComposerInteroperabilityWhenNoInitHook()
    {
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/'));
                TestCase::assertSame("OK\n", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => 'do_not_exists',
            ]
        );

        $this->assertEmpty($traces);
    }

    public function testComposerInteroperabilityWhenInitHookWorks()
    {
        $this->composerUpdateScenario(__DIR__ . '/app');
        $traces = $this->inWebServer(
            function ($execute) {
                $output = $execute(GetSpec::create('default', '/'));
                TestCase::assertSame("OK\n", $output);
            },
            __DIR__ . "/app/index.php",
            [],
            [
                'ddtrace.request_init_hook' => __DIR__ . '/../../bridge/dd_wrap_autoloader.php',
            ]
        );

        $this->assertNotEmpty($traces);
    }

    /**
     * Given a path to a composer working directory, this method runs composer update in it.
     *
     * @param string $workingDir
     */
    private function composerUpdateScenario($workingDir)
    {
        exec(
            "composer --working-dir='$workingDir' update -q",
            $output,
            $return
        );
        if (0 !== $return) {
            $this->fail('Error while preparing the env: ' . implode("\n", $output));
        }
    }
}
