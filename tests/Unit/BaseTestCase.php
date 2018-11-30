<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;
use PHPUnit\Framework;


abstract class BaseTestCase extends Framework\TestCase
{
    protected function setUp()
    {
        Configuration::clear();
        parent::setUp();
    }

    protected function tearDown()
    {
        \Mockery::close();
        parent::tearDown();
    }
}
