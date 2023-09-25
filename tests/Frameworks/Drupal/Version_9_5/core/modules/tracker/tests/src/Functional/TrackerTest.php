<?php

namespace Drupal\Tests\tracker\Functional;

use Drupal\comment\CommentInterface;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\system\Functional\Cache\AssertPageCacheContextsAndTagsTrait;

/**
 * Create and delete nodes and check for their display in the tracker listings.
 *
 * @group tracker
 */
class TrackerTest extends BrowserTestBase {

  use CommentTestTrait;
  use AssertPageCacheContextsAndTagsTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'block',
    'comment',
    'tracker',
    'history',
    'node_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The main user for testing.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * A second user that will 'create' comments and nodes.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $otherUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    $permissions = ['access comments', 'create page content', 'post comments', 'skip comment approval'];
    $this->user = $this->drupalCreateUser($permissions);
    $this->otherUser = $this->drupalCreateUser($permissions);
    $this->addDefaultCommentField('node', 'page');
    user_role_grant_permissions(AccountInterface::ANONYMOUS_ROLE, [
      'access content',
      'access user profiles',
    ]);
    $this->drupalPlaceBlock('local_tasks_block', ['id' => 'page_tabs_block']);
    $this->drupalPlaceBlock('local_actions_block', ['id' => 'page_actions_block']);
  }

  /**
   * Tests for the presence of nodes on the global tracker listing.
   */
  public function testTrackerAll() {
    $this->drupalLogin($this->user);

    $unpublished = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'status' => 0,
    ]);
    $published = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'status' => 1,
    ]);

    $this->drupalGet('activity');
    $this->assertSession()->pageTextNotContains($unpublished->label());
    $this->assertSession()->pageTextContains($published->label());
    $this->assertSession()->linkExists('My recent content', 0, 'User tab shows up on the global tracker page.');

    // Assert cache contexts, specifically the pager and node access contexts.
    $this->assertCacheContexts(['languages:language_interface', 'route', 'theme', 'url.query_args:' . MainContentViewSubscriber::WRAPPER_FORMAT, 'url.query_args.pagers:0', 'user.node_grants:view', 'user']);
    // Assert cache tags for the action/tabs blocks, visible node, and node list
    // cache tag.
    $expected_tags = Cache::mergeTags($published->getCacheTags(), $published->getOwner()->getCacheTags());
    // Because the 'user.permissions' cache context is being optimized away.
    $role_tags = [];
    foreach ($this->user->getRoles() as $rid) {
      $role_tags[] = "config:user.role.$rid";
    }
    $expected_tags = Cache::mergeTags($expected_tags, $role_tags);
    $block_tags = [
      'block_view',
      'local_task',
      'config:block.block.page_actions_block',
      'config:block.block.page_tabs_block',
      'config:block_list',
    ];
    $expected_tags = Cache::mergeTags($expected_tags, $block_tags);
    $additional_tags = [
      'node_list',
      'rendered',
    ];
    $expected_tags = Cache::mergeTags($expected_tags, $additional_tags);
    $this->assertCacheTags($expected_tags);

    // Delete a node and ensure it no longer appears on the tracker.
    $published->delete();
    $this->drupalGet('activity');
    $this->assertSession()->pageTextNotContains($published->label());

    // Test proper display of time on activity page when comments are disabled.
    // Disable comments.
    FieldStorageConfig::loadByName('node', 'comment')->delete();
    $node = $this->drupalCreateNode([
      // This title is required to trigger the custom changed time set in the
      // node_test module. This is needed in order to ensure a sufficiently
      // large 'time ago' interval that isn't numbered in seconds.
      'title' => 'testing_node_presave',
      'status' => 1,
    ]);

    $this->drupalGet('activity');
    $this->assertSession()->pageTextContains($node->label());
    $this->assertSession()->pageTextContains(\Drupal::service('date.formatter')->formatTimeDiffSince($node->getChangedTime()));
  }

  /**
   * Tests for the presence of nodes on a user's tracker listing.
   */
  public function testTrackerUser() {
    $this->drupalLogin($this->user);

    $unpublished = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'uid' => $this->user->id(),
      'status' => 0,
    ]);
    $my_published = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'uid' => $this->user->id(),
      'status' => 1,
    ]);
    $other_published_no_comment = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'uid' => $this->otherUser->id(),
      'status' => 1,
    ]);
    $other_published_my_comment = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'uid' => $this->otherUser->id(),
      'status' => 1,
    ]);
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    $this->drupalGet('comment/reply/node/' . $other_published_my_comment->id() . '/comment');
    $this->submitForm($comment, 'Save');

    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertSession()->pageTextNotContains($unpublished->label());
    $this->assertSession()->pageTextContains($my_published->label());
    $this->assertSession()->pageTextNotContains($other_published_no_comment->label());
    $this->assertSession()->pageTextContains($other_published_my_comment->label());

    // Assert cache contexts.
    $this->assertCacheContexts(['languages:language_interface', 'route', 'theme', 'url.query_args:' . MainContentViewSubscriber::WRAPPER_FORMAT, 'url.query_args.pagers:0', 'user', 'user.node_grants:view']);
    // Assert cache tags for the visible nodes (including owners) and node list
    // cache tag.
    $expected_tags = Cache::mergeTags($my_published->getCacheTags(), $my_published->getOwner()->getCacheTags());
    $expected_tags = Cache::mergeTags($expected_tags, $other_published_my_comment->getCacheTags());
    $expected_tags = Cache::mergeTags($expected_tags, $other_published_my_comment->getOwner()->getCacheTags());
    // Because the 'user.permissions' cache context is being optimized away.
    $role_tags = [];
    foreach ($this->user->getRoles() as $rid) {
      $role_tags[] = "config:user.role.$rid";
    }
    $expected_tags = Cache::mergeTags($expected_tags, $role_tags);
    $block_tags = [
      'block_view',
      'local_task',
      'config:block.block.page_actions_block',
      'config:block.block.page_tabs_block',
      'config:block_list',
    ];
    $expected_tags = Cache::mergeTags($expected_tags, $block_tags);
    $additional_tags = [
      'node_list',
      'rendered',
    ];
    $expected_tags = Cache::mergeTags($expected_tags, $additional_tags);

    $this->assertCacheTags($expected_tags);
    $this->assertCacheContexts(['languages:language_interface', 'route', 'theme', 'url.query_args:' . MainContentViewSubscriber::WRAPPER_FORMAT, 'url.query_args.pagers:0', 'user', 'user.node_grants:view']);

    $this->assertSession()->linkExists($my_published->label());
    $this->assertSession()->linkNotExists($unpublished->label());
    // Verify that title and tab title have been set correctly.
    $this->assertSession()->pageTextContains('Activity');
    $this->assertSession()->titleEquals($this->user->getAccountName() . ' | Drupal');

    // Verify that unpublished comments are removed from the tracker.
    $admin_user = $this->drupalCreateUser([
      'post comments',
      'administer comments',
      'access user profiles',
    ]);
    $this->drupalLogin($admin_user);
    $this->drupalGet('comment/1/edit');
    $this->submitForm(['status' => CommentInterface::NOT_PUBLISHED], 'Save');
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertSession()->pageTextNotContains($other_published_my_comment->label());

    // Test escaping of title on user's tracker tab.
    \Drupal::service('module_installer')->install(['user_hooks_test']);
    Cache::invalidateTags(['rendered']);
    \Drupal::state()->set('user_hooks_test_user_format_name_alter', TRUE);
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertSession()->assertEscaped('<em>' . $this->user->id() . '</em>');

    \Drupal::state()->set('user_hooks_test_user_format_name_alter_safe', TRUE);
    Cache::invalidateTags(['rendered']);
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertSession()->assertNoEscaped('<em>' . $this->user->id() . '</em>');
    $this->assertSession()->responseContains('<em>' . $this->user->id() . '</em>');
  }

  /**
   * Tests the metadata for the "new"/"updated" indicators.
   */
  public function testTrackerHistoryMetadata() {
    $this->drupalLogin($this->user);

    // Create a page node.
    $edit = [
      'title' => $this->randomMachineName(8),
    ];
    $node = $this->drupalCreateNode($edit);

    // Verify that the history metadata is present.
    $this->drupalGet('activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->getChangedTime());
    $this->drupalGet('activity/' . $this->user->id());
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->getChangedTime());
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->getChangedTime());

    // Add a comment to the page, make sure it is created after the node by
    // sleeping for one second, to ensure the last comment timestamp is
    // different from before.
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    sleep(1);
    $this->drupalGet('comment/reply/node/' . $node->id() . '/comment');
    $this->submitForm($comment, 'Save');
    // Reload the node so that comment.module's hook_node_load()
    // implementation can set $node->last_comment_timestamp for the freshly
    // posted comment.
    $node = Node::load($node->id());

    // Verify that the history metadata is updated.
    $this->drupalGet('activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->get('comment')->last_comment_timestamp);
    $this->drupalGet('activity/' . $this->user->id());
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->get('comment')->last_comment_timestamp);
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->get('comment')->last_comment_timestamp);

    // Log out, now verify that the metadata is still there, but the library is
    // not.
    $this->drupalLogout();
    $this->drupalGet('activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->get('comment')->last_comment_timestamp, FALSE);
    $this->drupalGet('user/' . $this->user->id() . '/activity');
    $this->assertHistoryMetadata($node->id(), $node->getChangedTime(), $node->get('comment')->last_comment_timestamp, FALSE);
  }

  /**
   * Tests for ordering on a users tracker listing when comments are posted.
   */
  public function testTrackerOrderingNewComments() {
    $this->drupalLogin($this->user);

    $node_one = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
    ]);

    $node_two = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
    ]);

    // Now get otherUser to track these pieces of content.
    $this->drupalLogin($this->otherUser);

    // Add a comment to the first page.
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    $this->drupalGet('comment/reply/node/' . $node_one->id() . '/comment');
    $this->submitForm($comment, 'Save');

    // If the comment is posted in the same second as the last one then Drupal
    // can't tell the difference, so we wait one second here.
    sleep(1);

    // Add a comment to the second page.
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    $this->drupalGet('comment/reply/node/' . $node_two->id() . '/comment');
    $this->submitForm($comment, 'Save');

    // We should at this point have in our tracker for otherUser:
    // 1. node_two
    // 2. node_one
    // Because that's the reverse order of the posted comments.

    // Now we're going to post a comment to node_one which should jump it to the
    // top of the list.

    $this->drupalLogin($this->user);
    // If the comment is posted in the same second as the last one then Drupal
    // can't tell the difference, so we wait one second here.
    sleep(1);

    // Add a comment to the second page.
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    $this->drupalGet('comment/reply/node/' . $node_one->id() . '/comment');
    $this->submitForm($comment, 'Save');

    // Switch back to the otherUser and assert that the order has swapped.
    $this->drupalLogin($this->otherUser);
    $this->drupalGet('user/' . $this->otherUser->id() . '/activity');
    // This is a cheeky way of asserting that the nodes are in the right order
    // on the tracker page.
    // It's almost certainly too brittle.
    $pattern = '/' . preg_quote($node_one->getTitle()) . '.+' . preg_quote($node_two->getTitle()) . '/s';
    // Verify that the most recent comment on node appears at the top of
    // tracker.
    $this->assertSession()->responseMatches($pattern);
  }

  /**
   * Tests that existing nodes are indexed by cron.
   */
  public function testTrackerCronIndexing() {
    $this->drupalLogin($this->user);

    // Create 3 nodes.
    $edits = [];
    $nodes = [];
    for ($i = 1; $i <= 3; $i++) {
      $edits[$i] = [
        'title' => $this->randomMachineName(),
      ];
      $nodes[$i] = $this->drupalCreateNode($edits[$i]);
    }

    // Add a comment to the last node as other user.
    $this->drupalLogin($this->otherUser);
    $comment = [
      'subject[0][value]' => $this->randomMachineName(),
      'comment_body[0][value]' => $this->randomMachineName(20),
    ];
    $this->drupalGet('comment/reply/node/' . $nodes[3]->id() . '/comment');
    $this->submitForm($comment, 'Save');

    // Create an unpublished node.
    $unpublished = $this->drupalCreateNode([
      'title' => $this->randomMachineName(8),
      'status' => 0,
    ]);

    $this->drupalGet('activity');
    $this->assertSession()->responseNotContains($unpublished->label());

    // Start indexing backwards from node 4.
    \Drupal::state()->set('tracker.index_nid', 4);

    // Clear the current tracker tables and rebuild them.
    $connection = Database::getConnection();
    $connection->delete('tracker_node')
      ->execute();
    $connection->delete('tracker_user')
      ->execute();
    tracker_cron();

    $this->drupalLogin($this->user);

    // Fetch the user's tracker.
    $this->drupalGet('activity/' . $this->user->id());

    // Assert that all node titles are displayed.
    foreach ($nodes as $i => $node) {
      $this->assertSession()->pageTextContains($node->label());
    }

    // Fetch the site-wide tracker.
    $this->drupalGet('activity');

    // Assert that all node titles are displayed.
    foreach ($nodes as $i => $node) {
      $this->assertSession()->pageTextContains($node->label());
    }
  }

  /**
   * Tests that publish/unpublish works at admin/content/node.
   */
  public function testTrackerAdminUnpublish() {
    \Drupal::service('module_installer')->install(['views']);
    $admin_user = $this->drupalCreateUser([
      'access content overview',
      'administer nodes',
      'bypass node access',
    ]);
    $this->drupalLogin($admin_user);

    $node = $this->drupalCreateNode([
      'title' => $this->randomMachineName(),
    ]);

    // Assert that the node is displayed.
    $this->drupalGet('activity');
    $this->assertSession()->pageTextContains($node->label());

    // Unpublish the node and ensure that it's no longer displayed.
    $edit = [
      'action' => 'node_unpublish_action',
      'node_bulk_form[0]' => $node->id(),
    ];
    $this->drupalGet('admin/content');
    $this->submitForm($edit, 'Apply to selected items');

    $this->drupalGet('activity');
    $this->assertSession()->pageTextContains('No content available.');
  }

  /**
   * Passes if the appropriate history metadata exists.
   *
   * Verify the data-history-node-id, data-history-node-timestamp and
   * data-history-node-last-comment-timestamp attributes, which are used by the
   * drupal.tracker-history library to add the appropriate "new" and "updated"
   * indicators, as well as the "x new" replies link to the tracker.
   * We do this in JavaScript to prevent breaking the render cache.
   *
   * @param int $node_id
   *   A node ID, that must exist as a data-history-node-id attribute
   * @param int $node_timestamp
   *   A node timestamp, that must exist as a data-history-node-timestamp
   *   attribute.
   * @param int $node_last_comment_timestamp
   *   A node's last comment timestamp, that must exist as a
   *   data-history-node-last-comment-timestamp attribute.
   * @param bool $library_is_present
   *   Whether the drupal.tracker-history library should be present or not.
   *
   * @internal
   */
  public function assertHistoryMetadata(int $node_id, int $node_timestamp, int $node_last_comment_timestamp, bool $library_is_present = TRUE): void {
    $settings = $this->getDrupalSettings();
    $this->assertSame($library_is_present, isset($settings['ajaxPageState']) && in_array('tracker/history', explode(',', $settings['ajaxPageState']['libraries'])), 'drupal.tracker-history library is present.');
    $this->assertSession()->elementsCount('xpath', '//table/tbody/tr/td[@data-history-node-id="' . $node_id . '" and @data-history-node-timestamp="' . $node_timestamp . '"]', 1);
    $this->assertSession()->elementsCount('xpath', '//table/tbody/tr/td[@data-history-node-last-comment-timestamp="' . $node_last_comment_timestamp . '"]', 1);
  }

}
