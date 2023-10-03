<?php

namespace Drupal\Tests\search\Kernel\Migrate\d6;

use Drupal\Core\Database\Database;
use Drupal\Tests\migrate_drupal\Kernel\d6\MigrateDrupal6TestBase;
use Drupal\search\Entity\SearchPage;

/**
 * Upgrade search page variables.
 *
 * @group migrate_drupal_6
 */
class MigrateSearchPageTest extends MigrateDrupal6TestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['search'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->executeMigration('search_page');
  }

  /**
   * Tests Drupal 6 search settings to Drupal 8 search page entity migration.
   */
  public function testSearchPage() {
    $id = 'node_search';
    /** @var \Drupal\search\Entity\SearchPage $search_page */
    $search_page = SearchPage::load($id);
    $this->assertSame($id, $search_page->id());
    $configuration = $search_page->getPlugin()->getConfiguration();
    $this->assertSame([
      'comments' => 5,
      'promote' => 0,
      'recent' => 0,
      'relevance' => 2,
      'sticky' => 8,
      'views' => 1,
    ], $configuration['rankings']);
    $this->assertSame('node', $search_page->getPath());

    // Test that we can re-import using the EntitySearchPage destination.
    Database::getConnection('default', 'migrate')
      ->update('variable')
      ->fields(['value' => serialize(4)])
      ->condition('name', 'node_rank_comments')
      ->execute();

    /** @var \Drupal\migrate\Plugin\MigrationInterface $migration */
    $migration = $this->getMigration('search_page');
    // Indicate we're rerunning a migration that's already run.
    $migration->getIdMap()->prepareUpdate();
    $this->executeMigration($migration);

    $configuration = SearchPage::load($id)->getPlugin()->getConfiguration();
    $this->assertSame(4, $configuration['rankings']['comments']);

    // Test that a configurable search without a configuration imports. Do this
    // by removing the node rankings from the source database.
    Database::getConnection('default', 'migrate')
      ->delete('variable')
      ->condition('name', 'node_rank_%', 'LIKE')
      ->execute();

    $migration = $this->getMigration('search_page');
    $migration->getIdMap()->prepareUpdate();
    $this->executeMigration($migration);

    $configuration = SearchPage::load($id)->getPlugin()->getConfiguration();
    $this->assertSame([], $configuration['rankings']);
  }

}
