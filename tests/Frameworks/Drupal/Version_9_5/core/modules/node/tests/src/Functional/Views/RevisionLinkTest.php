<?php

namespace Drupal\Tests\node\Functional\Views;

/**
 * Tests the different revision link handlers.
 *
 * @group node
 *
 * @see \Drupal\node\Plugin\views\field\RevisionLink
 * @see \Drupal\node\Plugin\views\field\RevisionLinkDelete
 * @see \Drupal\node\Plugin\views\field\RevisionLinkRevert
 */
class RevisionLinkTest extends NodeTestBase {

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_node_revision_links'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests revision links.
   */
  public function testRevisionLinks() {
    // Create one user which can view/revert and delete and one which can only
    // do one of them.
    $this->drupalCreateContentType(['name' => 'page', 'type' => 'page']);
    $account = $this->drupalCreateUser([
      'revert all revisions',
      'view all revisions',
      'delete all revisions',
      'edit any page content',
      'delete any page content',
    ]);
    $this->drupalLogin($account);
    // Create two nodes, one without an additional revision and one with a
    // revision.
    $nodes = [
      $this->drupalCreateNode(),
      $this->drupalCreateNode(),
    ];

    $first_revision = $nodes[1]->getRevisionId();
    // Create revision of the node.
    $nodes[1]->setNewRevision();
    $nodes[1]->save();
    $second_revision = $nodes[1]->getRevisionId();

    $this->drupalGet('test-node-revision-links');
    $this->assertSession()->statusCodeEquals(200);
    // The first node revision should link to the node directly as you get an
    // access denied if you link to the revision.
    $url = $nodes[0]->toUrl()->toString();
    $this->assertSession()->linkByHrefExists($url);
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $nodes[0]->getRevisionId() . '/view');
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $nodes[0]->getRevisionId() . '/delete');
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $nodes[0]->getRevisionId() . '/revert');

    // For the second node the current revision got set to the last revision, so
    // the first one should also link to the node page itself.
    $url = $nodes[1]->toUrl()->toString();
    $this->assertSession()->linkByHrefExists($url);
    $this->assertSession()->linkByHrefExists($url . '/revisions/' . $first_revision . '/view');
    $this->assertSession()->linkByHrefExists($url . '/revisions/' . $first_revision . '/delete');
    $this->assertSession()->linkByHrefExists($url . '/revisions/' . $first_revision . '/revert');
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $second_revision . '/view');
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $second_revision . '/delete');
    $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $second_revision . '/revert');

    $accounts = [
      'view' => $this->drupalCreateUser(['view all revisions']),
      'revert' => $this->drupalCreateUser([
        'revert all revisions',
        'edit any page content',
      ]),
      'delete' => $this->drupalCreateUser([
        'delete all revisions',
        'delete any page content',
      ]),
    ];

    $url = $nodes[1]->toUrl()->toString();
    // Render the view with users which can only delete/revert revisions.
    foreach ($accounts as $allowed_operation => $account) {
      $this->drupalLogin($account);
      $this->drupalGet('test-node-revision-links');
      // Check expected links.
      foreach (['revert', 'delete'] as $operation) {
        if ($operation == $allowed_operation) {
          $this->assertSession()->linkByHrefExists($url . '/revisions/' . $first_revision . '/' . $operation);
        }
        else {
          $this->assertSession()->linkByHrefNotExists($url . '/revisions/' . $first_revision . '/' . $operation);
        }
      }
    }
  }

}
