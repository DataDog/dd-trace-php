<?php

namespace DDTrace\Tests\Unit;

use DDTrace\ID;

final class IDTest extends BaseTestCase
{
    public function testMaxId()
    {
        $this->assertSame(
            PHP_INT_MAX,
            ID::getMaxId()
        );
    }

    public function testGenerateLength()
    {
        $id = ID::generate();
        $this->assertTrue(is_numeric($id));
        $this->assertGreaterThan(0, $id);
        $this->assertLessThanOrEqual(ID::getMaxId(), $id);
    }
}
