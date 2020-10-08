<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

abstract class MultiPHPUnitVersionAdapter extends TestCase
{
    abstract protected function ddSetUp();

    abstract protected function ddTearDown();

    protected function setUp()
    {
        parent::setUp();
        $this->ddSetUp();
    }

    protected function tearDown()
    {
        $this->ddTearDown();
        parent::tearDown();
    }
}
