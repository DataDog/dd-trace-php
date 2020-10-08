<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

abstract class MultiPHPUnitVersionAdapter extends TestCase
{
    abstract protected function ddSetUp();

    abstract protected function ddTearDown();

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        static::ddSetUpBeforeClass();
    }

    public static function tearDownAfterClass()
    {
        static::ddTearDownAfterClass();
        parent::tearDownAfterClass();
    }

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
