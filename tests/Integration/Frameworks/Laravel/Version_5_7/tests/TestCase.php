<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    public function setUp()
    {
        // Laravel > 5.4 set the constant LARAVEL_START in index.php rather than in the bootstrap. Tests do not use
        // index.php as an entry point, they directly load the bootstrap/app.php file. Given that we use the
        // constant LARAVEL_START, we set it here as it would be done in 'public/index.php'.
        if (!defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(true));
        }

        parent::setUp();
    }
}
