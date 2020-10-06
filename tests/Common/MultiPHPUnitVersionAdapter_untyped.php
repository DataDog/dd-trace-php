<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

abstract class MultiPHPUnitVersionAdapter extends TestCase
{
    abstract protected function afterSetUp();

    abstract protected function beforeTearDown();

    protected function setUp()
    {
        parent::setUp();
        $this->afterSetUp();
    }

    protected function tearDown()
    {
        $this->beforeTearDown();
        parent::tearDown();
    }
}
