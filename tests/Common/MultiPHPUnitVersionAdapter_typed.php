<?php

namespace DDTrace\Tests\Common;

use PHPUnit\Framework\TestCase;

abstract class MultiPHPUnitVersionAdapter extends TestCase
{
    abstract protected function ddSetUp();

    abstract protected function ddTearDown();

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::ddSetUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        static::ddTearDownAfterClass();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ddSetUp();
    }

    protected function tearDown(): void
    {
        $this->ddTearDown();
        parent::tearDown();
    }
}
