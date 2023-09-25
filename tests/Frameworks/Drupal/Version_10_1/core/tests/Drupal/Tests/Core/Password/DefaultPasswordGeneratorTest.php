<?php

namespace Drupal\Tests\Core\Password;

use Drupal\Core\Password\DefaultPasswordGenerator;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for password generator.
 *
 * @coversDefaultClass \Drupal\Core\Password\DefaultPasswordGenerator
 * @group System
 */
class DefaultPasswordGeneratorTest extends UnitTestCase {

  /**
   * @covers ::generate
   */
  public function testGenerate() {
    $generator = new DefaultPasswordGenerator();
    $password = $generator->generate();
    $this->assertEquals(10, strlen($password));

    $password = $generator->generate(32);
    $this->assertEquals(32, strlen($password));
  }

}
