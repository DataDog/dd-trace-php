<?php

namespace Drupal\taxonomy\Tests;

@trigger_error(__NAMESPACE__ . '\TaxonomyTestBase is deprecated in Drupal 8.4.0 and will be removed before Drupal 9.0.0. Instead, use \Drupal\Tests\taxonomy\Functional\TaxonomyTestBase', E_USER_DEPRECATED);

use Drupal\simpletest\WebTestBase;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;

/**
 * Provides common helper methods for Taxonomy module tests.
 *
 * @deprecated in drupal:8.?.? and is removed from drupal:9.0.0.
 *   Use \Drupal\Tests\taxonomy\Functional\TaxonomyTestBase instead.
 */
abstract class TaxonomyTestBase extends WebTestBase {

  use TaxonomyTestTrait;
  use EntityReferenceTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['taxonomy', 'block'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->drupalPlaceBlock('system_breadcrumb_block');

    // Create Basic page and Article node types.
    if ($this->profile != 'standard') {
      $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    }
  }

}
