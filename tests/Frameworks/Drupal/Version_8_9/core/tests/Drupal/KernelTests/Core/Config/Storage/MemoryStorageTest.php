<?php

namespace Drupal\KernelTests\Core\Config\Storage;

use Drupal\Core\Config\MemoryStorage;

/**
 * Tests MemoryStorage operations.
 *
 * @group config
 */
class MemoryStorageTest extends ConfigStorageTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->storage = new MemoryStorage();
    // ::listAll() verifications require other configuration data to exist.
    $this->storage->write('system.performance', []);
  }

  /**
   * {@inheritdoc}
   */
  protected function read($name) {
    return $this->storage->read($name);
  }

  /**
   * {@inheritdoc}
   */
  protected function insert($name, $data) {
    $this->storage->write($name, $data);
  }

  /**
   * {@inheritdoc}
   */
  protected function update($name, $data) {
    $this->storage->write($name, $data);
  }

  /**
   * {@inheritdoc}
   */
  protected function delete($name) {
    $this->storage->delete($name);
  }

  /**
   * {@inheritdoc}
   */
  public function testInvalidStorage() {
    $this->markTestSkipped('MemoryStorage cannot be invalid.');
  }

}
