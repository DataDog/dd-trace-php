<?php

namespace Drupal\Tests\aggregator\Kernel;

use Drupal\aggregator\Entity\Item;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests clean handling of an item with a missing feed ID.
 *
 * @group aggregator
 * @group legacy
 */
class ItemWithoutFeedTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['aggregator', 'options'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('aggregator_feed');
    $this->installEntitySchema('aggregator_item');
  }

  /**
   * Tests attempting to create a feed item without a feed.
   */
  public function testEntityCreation() {
    $entity = Item::create([
      'title' => 'Llama 2',
      'path' => 'https://groups.drupal.org/',
    ]);
    $violations = $entity->validate();
    $this->assertCount(1, $violations);
  }

}
