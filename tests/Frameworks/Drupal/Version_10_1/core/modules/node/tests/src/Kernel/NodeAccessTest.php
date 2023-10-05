<?php

namespace Drupal\Tests\node\Kernel;

/**
 * Tests basic node_access functionality.
 *
 * @group node
 */
class NodeAccessTest extends NodeAccessTestBase {

  /**
   * Runs basic tests for node_access function.
   */
  public function testNodeAccess() {
    // Ensures user without 'access content' permission can do nothing.
    $web_user1 = $this->drupalCreateUser([
      'create page content',
      'edit any page content',
      'delete any page content',
    ]);
    $node1 = $this->drupalCreateNode(['type' => 'page']);
    $this->assertNodeCreateAccess($node1->bundle(), FALSE, $web_user1);
    $this->assertNodeAccess([
      'view' => FALSE,
      'update' => FALSE,
      'delete' => FALSE,
    ], $node1, $web_user1);

    // Ensures user with 'bypass node access' permission can do everything.
    $web_user2 = $this->drupalCreateUser(['bypass node access']);
    $node2 = $this->drupalCreateNode(['type' => 'page']);
    $this->assertNodeCreateAccess($node2->bundle(), TRUE, $web_user2);
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => TRUE,
      'delete' => TRUE,
    ], $node2, $web_user2);

    // User cannot 'view own unpublished content'.
    $web_user3 = $this->drupalCreateUser(['access content']);
    $node3 = $this->drupalCreateNode([
      'status' => 0,
      'uid' => $web_user3->id(),
    ]);
    $this->assertNodeAccess(['view' => FALSE], $node3, $web_user3);

    // User cannot create content without permission.
    $this->assertNodeCreateAccess($node3->bundle(), FALSE, $web_user3);

    // User can 'view own unpublished content', but another user cannot.
    $web_user4 = $this->drupalCreateUser([
      'access content',
      'view own unpublished content',
    ]);
    $web_user5 = $this->drupalCreateUser([
      'access content',
      'view own unpublished content',
    ]);
    $node4 = $this->drupalCreateNode([
      'status' => 0,
      'uid' => $web_user4->id(),
    ]);
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => FALSE,
    ], $node4, $web_user4);
    $this->assertNodeAccess(['view' => FALSE], $node4, $web_user5);

    // Tests the default access provided for a published node.
    $node5 = $this->drupalCreateNode();
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => FALSE,
      'delete' => FALSE,
    ], $node5, $web_user3);

    // Tests the "edit any BUNDLE" and "delete any BUNDLE" permissions.
    $web_user6 = $this->drupalCreateUser([
      'access content',
      'edit any page content',
      'delete any page content',
    ]);
    $node6 = $this->drupalCreateNode(['type' => 'page']);
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => TRUE,
      'delete' => TRUE,
    ], $node6, $web_user6);

    // Tests the "edit own BUNDLE" and "delete own BUNDLE" permission.
    $web_user7 = $this->drupalCreateUser([
      'access content',
      'edit own page content',
      'delete own page content',
    ]);
    // User should not be able to edit or delete nodes they do not own.
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => FALSE,
      'delete' => FALSE,
    ], $node6, $web_user7);

    // User should be able to edit or delete nodes they own.
    $node7 = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $web_user7->id(),
    ]);
    $this->assertNodeAccess([
      'view' => TRUE,
      'update' => TRUE,
      'delete' => TRUE,
    ], $node7, $web_user7);
  }

  /**
   * Tests operations not supported by node grants.
   */
  public function testUnsupportedOperation() {
    $this->enableModules(['node_access_test_empty']);
    $web_user = $this->drupalCreateUser(['access content']);
    $node = $this->drupalCreateNode();
    $this->assertNodeAccess(['random_operation' => FALSE], $node, $web_user);
  }

}
