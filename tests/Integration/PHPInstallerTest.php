<?php

namespace DDTrace\Tests\Integration;

use DDTrace\Tests\Common\BaseTestCase;

final class PHPInstallerTest extends BaseTestCase
{
    public static function ddSetUpBeforeClass()
    {
        require_once __DIR__ . '/../../dd-php-setup.php';
    }

    public function testIsTruthy()
    {
        $this->assertTrue(is_truthy('1'));
        $this->assertTrue(is_truthy('TrUe'));
        $this->assertTrue(is_truthy('yEs'));
        $this->assertTrue(is_truthy('EnAbLeD'));

        $this->assertFalse(is_truthy(null));
        $this->assertFalse(is_truthy('0'));
        $this->assertFalse(is_truthy('fAlSe'));
        $this->assertFalse(is_truthy('nO'));
        $this->assertFalse(is_truthy('dIsAbLeD'));
    }
}
