<?php

namespace Drupal\Tests\views\Functional\Entity;

use Drupal\comment\Tests\CommentTestTrait;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\Tests\views\Functional\ViewTestBase;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\Views;
use Drupal\comment\Entity\Comment;

/**
 * Tests the field plugin base integration with the entity system.
 *
 * @group views
 */
class FieldEntityTest extends ViewTestBase {

  use CommentTestTrait;

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_field_get_entity'];

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['node', 'comment'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE, $modules = ['views_test_config']): void {
    parent::setUp(FALSE, $modules);

    $this->drupalCreateContentType(['type' => 'page']);
    $this->addDefaultCommentField('node', 'page');

    ViewTestData::createTestViews(static::class, $modules);
  }

  /**
   * Tests the getEntity method.
   */
  public function testGetEntity() {
    // The view is a view of comments, their nodes and their authors, so there
    // are three layers of entities.

    $account = User::create(['name' => $this->randomMachineName(), 'bundle' => 'user']);
    $account->save();

    $node = Node::create([
      'uid' => $account->id(),
      'type' => 'page',
      'title' => $this->randomString(),
    ]);
    $node->save();
    $comment = Comment::create([
      'uid' => $account->id(),
      'entity_id' => $node->id(),
      'entity_type' => 'node',
      'field_name' => 'comment',
    ]);
    $comment->save();

    $user = $this->drupalCreateUser(['access comments']);
    $this->drupalLogin($user);

    $view = Views::getView('test_field_get_entity');
    $this->executeView($view);
    $row = $view->result[0];

    // Tests entities on the base level.
    $entity = $view->field['cid']->getEntity($row);
    $this->assertEquals($comment->id(), $entity->id(), 'Make sure the right comment entity got loaded.');
    // Tests entities as relationship on first level.
    $entity = $view->field['nid']->getEntity($row);
    $this->assertEquals($node->id(), $entity->id(), 'Make sure the right node entity got loaded.');
    // Tests entities as relationships on second level.
    $entity = $view->field['uid']->getEntity($row);
    $this->assertEquals($account->id(), $entity->id(), 'Make sure the right user entity got loaded.');
  }

}
