<?php

namespace Drupal\Tests\workspaces\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests uninstalling the Workspaces module.
 *
 * @group workspaces
 */
class WorkspacesUninstallTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  public static $modules = ['workspaces'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests deleting workspace entities and uninstalling Workspaces module.
   */
  public function testUninstallingWorkspace() {
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('/admin/modules/uninstall');
    $session = $this->assertSession();
    $session->linkExists('Remove workspaces');
    $this->clickLink('Remove workspaces');
    $session->pageTextContains('Are you sure you want to delete all workspaces?');
    $this->drupalPostForm('/admin/modules/uninstall/entity/workspace', [], 'Delete all workspaces');
    $this->drupalPostForm('admin/modules/uninstall', ['uninstall[workspaces]' => TRUE], 'Uninstall');
    $this->drupalPostForm(NULL, [], 'Uninstall');
    $session->pageTextContains('The selected modules have been uninstalled.');
    $session->pageTextNotContains('Workspaces');

    $this->assertFalse(\Drupal::database()->schema()->fieldExists('node_revision', 'workspace'));

    // Verify that the revision metadata key has been removed.
    $entity_type = \Drupal::entityDefinitionUpdateManager()->getEntityType('node');
    $revision_metadata_keys = $entity_type->get('revision_metadata_keys');
    $this->assertArrayNotHasKey('workspace', $revision_metadata_keys);
    $required_revision_metadata_keys = $entity_type->get('requiredRevisionMetadataKeys');
    $this->assertArrayNotHasKey('workspace', $required_revision_metadata_keys);
  }

}
