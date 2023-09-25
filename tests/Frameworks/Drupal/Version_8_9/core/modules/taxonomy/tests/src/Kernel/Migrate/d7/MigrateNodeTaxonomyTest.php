<?php

namespace Drupal\Tests\taxonomy\Kernel\Migrate\d7;

use Drupal\Tests\migrate_drupal\Kernel\d7\MigrateDrupal7TestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * @group taxonomy
 */
class MigrateNodeTaxonomyTest extends MigrateDrupal7TestBase {

  public static $modules = [
    'comment',
    'datetime',
    'image',
    'link',
    'menu_ui',
    'node',
    'taxonomy',
    'telephone',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('file');

    $this->migrateTaxonomyTerms();
    $this->migrateUsers(FALSE);
    $this->executeMigration('d7_node:article');
  }

  /**
   * Test node migration from Drupal 7 to 8.
   */
  public function testMigration() {
    $node = Node::load(2);
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertEqual(9, $node->field_tags[0]->target_id);
    $this->assertEqual(14, $node->field_tags[1]->target_id);
    $this->assertEqual(17, $node->field_tags[2]->target_id);
  }

}
