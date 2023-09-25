<?php

namespace Drupal\Tests\comment\Functional\Views;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\comment\Entity\Comment;
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Tests\comment\Functional\CommentTestBase as CommentBrowserTestBase;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Tests comment approval functionality.
 *
 * @group comment
 */
class CommentAdminTest extends CommentBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::service('module_installer')->install(['views']);
    $view = Views::getView('comment');
    $view->storage->enable()->save();
    \Drupal::service('router.builder')->rebuildIfNeeded();
  }

  /**
   * Tests comment approval functionality through admin/content/comment.
   */
  public function testApprovalAdminInterface() {
    // Set anonymous comments to require approval.
    user_role_change_permissions(RoleInterface::ANONYMOUS_ID, [
      'access comments' => TRUE,
      'post comments' => TRUE,
      'skip comment approval' => FALSE,
    ]);
    $this->drupalPlaceBlock('page_title_block');
    $this->drupalLogin($this->adminUser);
    // Ensure that doesn't require contact info.
    $this->setCommentAnonymous('0');

    // Test that the comments page loads correctly when there are no comments.
    $this->drupalGet('admin/content/comment');
    $this->assertSession()->pageTextContains('No comments available.');

    // Assert the expose filters on the admin page.
    $this->assertSession()->fieldExists('subject');
    $this->assertSession()->fieldExists('author_name');
    $this->assertSession()->fieldExists('langcode');

    $this->drupalLogout();

    // Post anonymous comment without contact info.
    $body = $this->getRandomGenerator()->sentences(4);
    $subject = Unicode::truncate(trim(Html::decodeEntities(strip_tags($body))), 29, TRUE, TRUE);
    $author_name = $this->randomMachineName();
    $this->drupalGet('comment/reply/node/' . $this->node->id() . '/comment');
    $this->submitForm([
      'name' => $author_name,
      'comment_body[0][value]' => $body,
    ], 'Save');
    $this->assertSession()->pageTextContains('Your comment has been queued for review by site administrators and will be published after approval.');

    // Get unapproved comment id.
    $this->drupalLogin($this->adminUser);
    $anonymous_comment4 = $this->getUnapprovedComment($subject);
    $anonymous_comment4 = Comment::create([
      'cid' => $anonymous_comment4,
      'subject' => $subject,
      'comment_body' => $body,
      'entity_id' => $this->node->id(),
      'entity_type' => 'node',
      'field_name' => 'comment',
    ]);
    $this->drupalLogout();

    $this->assertFalse($this->commentExists($anonymous_comment4), 'Anonymous comment was not published.');

    // Approve comment.
    $this->drupalLogin($this->adminUser);
    $edit = [];
    $edit['action'] = 'comment_publish_action';
    $edit['comment_bulk_form[0]'] = $anonymous_comment4->id();
    $this->drupalGet('admin/content/comment/approval');
    $this->submitForm($edit, 'Apply to selected items');

    $this->assertSession()->pageTextContains('Publish comment was applied to 1 item.');
    $this->drupalLogout();

    $this->drupalGet('node/' . $this->node->id());
    $this->assertTrue($this->commentExists($anonymous_comment4), 'Anonymous comment visible.');

    // Post 2 anonymous comments without contact info.
    $comments[] = $this->postComment($this->node, $this->randomMachineName(), $this->randomMachineName(), TRUE);
    $comments[] = $this->postComment($this->node, $this->randomMachineName(), $this->randomMachineName(), TRUE);

    // Publish multiple comments in one operation.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/comment/approval');
    $this->assertSession()->pageTextContains('Unapproved comments (2)');

    // Assert the expose filters on the admin page.
    $this->assertSession()->fieldExists('subject');
    $this->assertSession()->fieldExists('author_name');
    $this->assertSession()->fieldExists('langcode');

    $edit = [
      "action" => 'comment_publish_action',
      "comment_bulk_form[1]" => $comments[0]->id(),
      "comment_bulk_form[0]" => $comments[1]->id(),
    ];
    $this->submitForm($edit, 'Apply to selected items');
    $this->assertSession()->pageTextContains('Unapproved comments (0)');

    // Test message when no comments selected.
    $this->drupalGet('admin/content/comment');
    $this->submitForm([], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Select one or more comments to perform the update on.');

    // Test that comment listing shows the correct subject link.
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//table/tbody/tr/td/a[contains(@href, :href) and contains(@title, :title) and text()=:text]', [
      ':href' => $comments[0]->permalink()->toString(),
      ':title' => Unicode::truncate($comments[0]->get('comment_body')->value, 128),
      ':text' => $comments[0]->getSubject(),
    ]));

    // Verify that anonymous author name is displayed correctly.
    $this->assertSession()->pageTextContains($author_name . ' (not verified)');

    // Test that comment listing shows the correct subject link.
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//table/tbody/tr/td/a[contains(@href, :href) and contains(@title, :title) and text()=:text]', [
      ':href' => $anonymous_comment4->permalink()->toString(),
      ':title' => Unicode::truncate($body, 128),
      ':text' => $subject,
    ]));

    // Verify that anonymous author name is displayed correctly.
    $this->assertSession()->pageTextContains($author_name . ' (not verified)');

    // Delete multiple comments in one operation.
    $edit = [
      'action' => 'comment_delete_action',
      "comment_bulk_form[1]" => $comments[0]->id(),
      "comment_bulk_form[0]" => $comments[1]->id(),
      "comment_bulk_form[2]" => $anonymous_comment4->id(),
    ];
    $this->submitForm($edit, 'Apply to selected items');
    $this->assertSession()->pageTextContains('Are you sure you want to delete these comments and all their children?');
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains('No comments available.');

    // Make sure the label of unpublished node is not visible on listing page.
    $this->drupalGet('admin/content/comment');
    $this->postComment($this->node, $this->randomMachineName());
    $this->drupalLogout();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/comment');
    // Verify that comment admin can see the title of a published node.
    $this->assertSession()->pageTextContains(Html::escape($this->node->label()));
    $this->node->setUnpublished()->save();
    $this->assertFalse($this->node->isPublished(), 'Node is unpublished now.');
    $this->drupalGet('admin/content/comment');
    // Verify that comment admin cannot see the title of an unpublished node.
    $this->assertSession()->pageTextNotContains(Html::escape($this->node->label()));
    $this->drupalLogout();
    $node_access_user = $this->drupalCreateUser([
      'administer comments',
      'bypass node access',
    ]);
    $this->drupalLogin($node_access_user);
    $this->drupalGet('admin/content/comment');
    // Verify that comment admin with bypass node access permissions can still
    // see the title of a published node.
    $this->assertSession()->pageTextContains(Html::escape($this->node->label()));
  }

  /**
   * Tests commented entity label of admin view.
   */
  public function testCommentedEntityLabel() {
    \Drupal::service('module_installer')->install(['block_content']);
    \Drupal::service('router.builder')->rebuildIfNeeded();
    $bundle = BlockContentType::create([
      'id' => 'basic',
      'label' => 'basic',
      'revision' => FALSE,
    ]);
    $bundle->save();
    $block_content = BlockContent::create([
      'type' => 'basic',
      'label' => 'Some block title',
      'info' => 'Test block',
    ]);
    $block_content->save();

    // Create comment field on block_content.
    $this->addDefaultCommentField('block_content', 'basic', 'block_comment', CommentItemInterface::OPEN, 'block_comment');
    $this->drupalLogin($this->webUser);
    // Post a comment to node.
    $node_comment = $this->postComment($this->node, $this->randomMachineName(), $this->randomMachineName(), TRUE);
    // Post a comment to block content.
    $block_content_comment = $this->postComment($block_content, $this->randomMachineName(), $this->randomMachineName(), TRUE, 'block_comment');
    $this->drupalLogout();
    // Login as admin to test the admin comment page.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/content/comment');

    // Test that comment listing links to comment author.
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//table/tbody/tr[1]/td/a[contains(@href, :href) and text()=:text]', [
      ':href' => $this->webUser->toUrl()->toString(),
      ':text' => $this->webUser->label(),
    ]));
    $this->assertSession()->elementExists('xpath', $this->assertSession()->buildXPathQuery('//table/tbody/tr[2]/td/a[contains(@href, :href) and text()=:text]', [
      ':href' => $this->webUser->toUrl()->toString(),
      ':text' => $this->webUser->label(),
    ]));

    // Admin page contains label of both entities.
    $this->assertSession()->pageTextContains(Html::escape($this->node->label()));
    $this->assertSession()->pageTextContains(Html::escape($block_content->label()));
    // Admin page contains subject of both entities.
    $this->assertSession()->pageTextContains(Html::escape($node_comment->label()));
    $this->assertSession()->pageTextContains(Html::escape($block_content_comment->label()));
  }

}
