<?php

namespace Drupal\Tests\node\Functional;

use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Create a node and test node edit functionality.
 *
 * @group node
 */
class NodeEditFormTest extends NodeTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A normal logged in user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $webUser;

  /**
   * A user with permission to bypass content access checks.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * The node storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = ['block', 'node', 'datetime'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->webUser = $this->drupalCreateUser([
      'edit own page content',
      'create page content',
    ]);
    $this->adminUser = $this->drupalCreateUser([
      'bypass node access',
      'administer nodes',
    ]);
    $this->drupalPlaceBlock('local_tasks_block');

    $this->nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
  }

  /**
   * Checks node edit functionality.
   */
  public function testNodeEdit() {
    $this->drupalLogin($this->webUser);

    $title_key = 'title[0][value]';
    $body_key = 'body[0][value]';
    // Create node to edit.
    $edit = [];
    $edit[$title_key] = $this->randomMachineName(8);
    $edit[$body_key] = $this->randomMachineName(16);
    $this->drupalGet('node/add/page');
    $this->submitForm($edit, 'Save');

    // Check that the node exists in the database.
    $node = $this->drupalGetNodeByTitle($edit[$title_key]);
    $this->assertNotEmpty($node, 'Node found in database.');

    // Check that "edit" link points to correct page.
    $this->clickLink('Edit');
    $this->assertSession()->addressEquals($node->toUrl('edit-form'));

    // Check that the title and body fields are displayed with the correct values.
    // @todo Ideally assertLink would support HTML, but it doesn't.
    $this->assertSession()->responseContains('Edit<span class="visually-hidden">(active tab)</span>');
    $this->assertSession()->fieldValueEquals($title_key, $edit[$title_key]);
    $this->assertSession()->fieldValueEquals($body_key, $edit[$body_key]);

    // Edit the content of the node.
    $edit = [];
    $edit[$title_key] = $this->randomMachineName(8);
    $edit[$body_key] = $this->randomMachineName(16);
    // Stay on the current page, without reloading.
    $this->submitForm($edit, 'Save');

    // Check that the title and body fields are displayed with the updated values.
    $this->assertSession()->pageTextContains($edit[$title_key]);
    $this->assertSession()->pageTextContains($edit[$body_key]);

    // Log in as a second administrator user.
    $second_web_user = $this->drupalCreateUser([
      'administer nodes',
      'edit any page content',
    ]);
    $this->drupalLogin($second_web_user);
    // Edit the same node, creating a new revision.
    $this->drupalGet("node/" . $node->id() . "/edit");
    $edit = [];
    $edit['title[0][value]'] = $this->randomMachineName(8);
    $edit[$body_key] = $this->randomMachineName(16);
    $edit['revision'] = TRUE;
    $this->submitForm($edit, 'Save');

    // Ensure that the node revision has been created.
    $revised_node = $this->drupalGetNodeByTitle($edit['title[0][value]'], TRUE);
    $this->assertNotSame($node->getRevisionId(), $revised_node->getRevisionId(), 'A new revision has been created.');
    // Ensure that the node author is preserved when it was not changed in the
    // edit form.
    $this->assertSame($node->getOwnerId(), $revised_node->getOwnerId(), 'The node author has been preserved.');
    // Ensure that the revision authors are different since the revisions were
    // made by different users.
    $first_node_version = node_revision_load($node->getRevisionId());
    $second_node_version = node_revision_load($revised_node->getRevisionId());
    $this->assertNotSame($first_node_version->getRevisionUser()->id(), $second_node_version->getRevisionUser()->id(), 'Each revision has a distinct user.');

    // Check if the node revision checkbox is rendered on node edit form.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldExists('edit-revision', NULL);

    // Check that details form element opens when there are errors on child
    // elements.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $edit = [];
    // This invalid date will trigger an error.
    $edit['created[0][value][date]'] = $this->randomMachineName(8);
    // Get the current amount of open details elements.
    $open_details_elements = count($this->cssSelect('details[open="open"]'));
    $this->submitForm($edit, 'Save');
    // The node author details must be open.
    $this->assertSession()->responseContains('<details class="node-form-author js-form-wrapper form-wrapper" data-drupal-selector="edit-author" id="edit-author" open="open">');
    // Only one extra details element should now be open.
    $open_details_elements++;
    $this->assertCount($open_details_elements, $this->cssSelect('details[open="open"]'), 'Exactly one extra open &lt;details&gt; element found.');

    // Edit the same node, save it and verify it's unpublished after unchecking
    // the 'Published' boolean_checkbox and clicking 'Save'.
    $this->drupalGet("node/" . $node->id() . "/edit");
    $edit = ['status[value]' => FALSE];
    $this->submitForm($edit, 'Save');
    $this->nodeStorage->resetCache([$node->id()]);
    $node = $this->nodeStorage->load($node->id());
    $this->assertFalse($node->isPublished(), 'Node is unpublished');
  }

  /**
   * Tests changing a node's "authored by" field.
   */
  public function testNodeEditAuthoredBy() {
    $this->drupalLogin($this->adminUser);

    // Create node to edit.
    $body_key = 'body[0][value]';
    $edit = [];
    $edit['title[0][value]'] = $this->randomMachineName(8);
    $edit[$body_key] = $this->randomMachineName(16);
    $this->drupalGet('node/add/page');
    $this->submitForm($edit, 'Save');

    // Check that the node was authored by the currently logged in user.
    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);
    $this->assertSame($this->adminUser->id(), $node->getOwnerId(), 'Node authored by admin user.');

    $this->checkVariousAuthoredByValues($node, 'uid[0][target_id]');

    // Check that normal users cannot change the authored by information.
    $this->drupalLogin($this->webUser);
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->fieldNotExists('uid[0][target_id]');

    // Now test with the Autocomplete (Tags) field widget.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.page.default');
    $widget = $form_display->getComponent('uid');
    $widget['type'] = 'entity_reference_autocomplete_tags';
    $widget['settings'] = [
      'match_operator' => 'CONTAINS',
      'size' => 60,
      'placeholder' => '',
    ];
    $form_display->setComponent('uid', $widget);
    $form_display->save();

    $this->drupalLogin($this->adminUser);

    // Save the node without making any changes.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->submitForm([], 'Save');
    $this->nodeStorage->resetCache([$node->id()]);
    $node = $this->nodeStorage->load($node->id());
    $this->assertSame($this->webUser->id(), $node->getOwner()->id());

    $this->checkVariousAuthoredByValues($node, 'uid[target_id]');

    // Hide the 'authored by' field from the form.
    $form_display->removeComponent('uid')->save();

    // Check that saving the node without making any changes keeps the proper
    // author ID.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->submitForm([], 'Save');
    $this->nodeStorage->resetCache([$node->id()]);
    $node = $this->nodeStorage->load($node->id());
    $this->assertSame($this->webUser->id(), $node->getOwner()->id());
  }

  /**
   * Tests the node meta information.
   */
  public function testNodeMetaInformation() {
    // Check that regular users (i.e. without the 'administer nodes' permission)
    // can not see the meta information.
    $this->drupalLogin($this->webUser);
    $this->drupalGet('node/add/page');
    $this->assertSession()->pageTextNotContains('Not saved yet');

    // Create node to edit.
    $edit['title[0][value]'] = $this->randomMachineName(8);
    $edit['body[0][value]'] = $this->randomMachineName(16);
    $this->submitForm($edit, 'Save');

    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);
    $this->drupalGet("node/" . $node->id() . "/edit");
    $this->assertSession()->pageTextNotContains('Published');
    $this->assertSession()->pageTextNotContains($this->container->get('date.formatter')->format($node->getChangedTime(), 'short'));

    // Check that users with the 'administer nodes' permission can see the meta
    // information.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('node/add/page');
    $this->assertSession()->pageTextContains('Not saved yet');

    // Create node to edit.
    $edit['title[0][value]'] = $this->randomMachineName(8);
    $edit['body[0][value]'] = $this->randomMachineName(16);
    $this->submitForm($edit, 'Save');

    $node = $this->drupalGetNodeByTitle($edit['title[0][value]']);
    $this->drupalGet("node/" . $node->id() . "/edit");
    $this->assertSession()->pageTextContains('Published');
    $this->assertSession()->pageTextContains($this->container->get('date.formatter')->format($node->getChangedTime(), 'short'));
  }

  /**
   * Checks that the "authored by" works correctly with various values.
   *
   * @param \Drupal\node\NodeInterface $node
   *   A node object.
   * @param string $form_element_name
   *   The name of the form element to populate.
   */
  protected function checkVariousAuthoredByValues(NodeInterface $node, $form_element_name) {
    // Try to change the 'authored by' field to an invalid user name.
    $edit = [
      $form_element_name => 'invalid-name',
    ];
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('There are no users matching "invalid-name".');

    // Change the authored by field to an empty string, which should assign
    // authorship to the anonymous user (uid 0).
    $edit[$form_element_name] = '';
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->submitForm($edit, 'Save');
    $this->nodeStorage->resetCache([$node->id()]);
    $node = $this->nodeStorage->load($node->id());
    $uid = $node->getOwnerId();
    // Most SQL database drivers stringify fetches but entities are not
    // necessarily stored in a SQL database. At the same time, NULL/FALSE/""
    // won't do.
    $this->assertTrue($uid === 0 || $uid === '0', 'Node authored by anonymous user.');

    // Go back to the edit form and check that the correct value is displayed
    // in the author widget.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $anonymous_user = User::getAnonymousUser();
    $expected = $anonymous_user->label() . ' (' . $anonymous_user->id() . ')';
    $this->assertSession()->fieldValueEquals($form_element_name, $expected);

    // Change the authored by field to another user's name (that is not
    // logged in).
    $edit[$form_element_name] = $this->webUser->getAccountName();
    $this->submitForm($edit, 'Save');
    $this->nodeStorage->resetCache([$node->id()]);
    $node = $this->nodeStorage->load($node->id());
    $this->assertSame($this->webUser->id(), $node->getOwnerId(), 'Node authored by normal user.');
  }

}
