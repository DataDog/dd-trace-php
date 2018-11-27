<?php

namespace DDTrace\Tests\Unit;

use PHPUnit\Framework;


abstract class BaseTestCase extends Framework\TestCase
{
    protected function tearDown()
    {
        \Mockery::close();
        parent::tearDown();
    }
}
