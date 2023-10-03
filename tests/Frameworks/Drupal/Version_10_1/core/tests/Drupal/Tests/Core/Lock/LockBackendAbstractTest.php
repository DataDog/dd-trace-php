<?php

namespace Drupal\Tests\Core\Lock;

use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\Tests\Core\Lock\LockBackendAbstractTest
 * @group Lock
 */
class LockBackendAbstractTest extends UnitTestCase {

  /**
   * The Mocked LockBackendAbstract object.
   *
   * @var \Drupal\Core\Lock\LockBackendAbstract|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $lock;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->lock = $this->getMockForAbstractClass('Drupal\Core\Lock\LockBackendAbstract');
  }

  /**
   * Tests the wait() method when lockMayBeAvailable() returns TRUE.
   */
  public function testWaitFalse() {
    $this->lock->expects($this->any())
      ->method('lockMayBeAvailable')
      ->with($this->equalTo('test_name'))
      ->willReturn(TRUE);

    $this->assertFalse($this->lock->wait('test_name'));
  }

  /**
   * Tests the wait() method when lockMayBeAvailable() returns FALSE.
   *
   * Waiting could take 1 second so we need to extend the possible runtime.
   * @medium
   */
  public function testWaitTrue() {
    $this->lock->expects($this->any())
      ->method('lockMayBeAvailable')
      ->with($this->equalTo('test_name'))
      ->willReturn(FALSE);

    $this->assertTrue($this->lock->wait('test_name', 1));
  }

  /**
   * Tests the getLockId() method.
   */
  public function testGetLockId() {
    $lock_id = $this->lock->getLockId();
    $this->assertIsString($lock_id);
    // Example lock ID would be '7213141505232b6ee2cb967.27683891'.
    $this->assertMatchesRegularExpression('/[\da-f]+\.\d+/', $lock_id);
    // Test the same lock ID is returned a second time.
    $this->assertSame($lock_id, $this->lock->getLockId());
  }

}
