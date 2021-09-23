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
        $this->assertTrue(\is_truthy('1'));
        $this->assertTrue(\is_truthy('true'));
        $this->assertTrue(\is_truthy('TrUe'));
        $this->assertTrue(\is_truthy('yes'));
        $this->assertTrue(\is_truthy('yEs'));
        $this->assertTrue(\is_truthy('enabled'));
        $this->assertTrue(\is_truthy('EnAbLeD'));

        $this->assertFalse(\is_truthy(null));
        $this->assertFalse(\is_truthy('0'));
        $this->assertFalse(\is_truthy('false'));
        $this->assertFalse(\is_truthy('fAlSe'));
        $this->assertFalse(\is_truthy('no'));
        $this->assertFalse(\is_truthy('nO'));
        $this->assertFalse(\is_truthy('disabled'));
        $this->assertFalse(\is_truthy('dIsAbLeD'));
    }

    public function testBuildCommandNamesMatrix()
    {
        $names = \build_known_command_names_matrix(SUPPORTED_PHP_VERSIONS);

        $this->assertContains('php', $names);
        $this->assertContains('php-fpm', $names);

        // We test one version all combinations
        $this->assertContains('php8', $names);
        $this->assertContains('php80', $names);
        $this->assertContains('php8.0', $names);
        $this->assertContains('php8-fpm', $names);
        $this->assertContains('php80-fpm', $names);
        $this->assertContains('php8.0-fpm', $names);
        $this->assertContains('php-fpm8', $names);
        $this->assertContains('php-fpm80', $names);
        $this->assertContains('php-fpm8.0', $names);

        // We test only that all versions are present with at least one combination
        $this->assertContains('php54', $names);
        $this->assertContains('php55', $names);
        $this->assertContains('php56', $names);
        $this->assertContains('php70', $names);
        $this->assertContains('php71', $names);
        $this->assertContains('php72', $names);
        $this->assertContains('php73', $names);
        $this->assertContains('php74', $names);
        $this->assertContains('php80', $names);
        $this->assertContains('php81', $names);
    }
}
