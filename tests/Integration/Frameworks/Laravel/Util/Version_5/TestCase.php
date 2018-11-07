<?php

namespace DDTrace\Tests\Integration\Frameworks\Laravel\Util\Version_5;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;


abstract class TestCase extends BaseTestCase
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // This is set in phpunit.xml
        $versionUnderTest = getenv('LARAVEL_VERSION');
        $bootstrapScript = getenv('BOOTSTRAP_SCRIPT');
        $app = require __DIR__ . '/../../' . $versionUnderTest . '/' . $bootstrapScript;

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
