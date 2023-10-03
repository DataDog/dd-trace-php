<?php

namespace Drupal\Tests\node\Functional;

use Drupal\Component\Utility\Html;

/**
 * Tests that dangerous tags in the node title are escaped.
 *
 * @group node
 */
class NodeTitleXSSTest extends NodeTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests XSS functionality with a node entity.
   */
  public function testNodeTitleXSS() {
    // Prepare a user to do the stuff.
    $web_user = $this->drupalCreateUser([
      'create page content',
      'edit any page content',
    ]);
    $this->drupalLogin($web_user);

    $xss = '<script>alert("xss")</script>';
    $title = $xss . $this->randomMachineName();
    $edit = [];
    $edit['title[0][value]'] = $title;

    $this->drupalGet('node/add/page');
    $this->submitForm($edit, 'Preview');
    // Verify that harmful tags are escaped when previewing a node.
    $this->assertSession()->responseNotContains($xss);

    $settings = ['title' => $title];
    $node = $this->drupalCreateNode($settings);

    $this->drupalGet('node/' . $node->id());
    // Titles should be escaped.
    $this->assertSession()->responseContains('<title>' . Html::escape($title) . ' | Drupal</title>');
    $this->assertSession()->responseNotContains($xss);

    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->responseNotContains($xss);
  }

}
