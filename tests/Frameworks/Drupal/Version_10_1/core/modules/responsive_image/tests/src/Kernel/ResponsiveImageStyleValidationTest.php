<?php

namespace Drupal\Tests\responsive_image\Kernel;

use Drupal\KernelTests\Core\Config\ConfigEntityValidationTestBase;
use Drupal\responsive_image\Entity\ResponsiveImageStyle;

/**
 * Tests validation of responsive_image_style entities.
 *
 * @group responsive_image
 */
class ResponsiveImageStyleValidationTest extends ConfigEntityValidationTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['breakpoint', 'image', 'responsive_image'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entity = ResponsiveImageStyle::create([
      'id' => 'test',
      'label' => 'Test',
    ]);
    $this->entity->save();
  }

}
