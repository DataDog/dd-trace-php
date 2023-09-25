<?php

namespace Drupal\Tests\comment\Functional\Views;

/**
 * Tests the comment rss row plugin.
 *
 * @group comment
 * @see \Drupal\comment\Plugin\views\row\Rss
 */
class RowRssTest extends CommentTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_comment_rss'];

  /**
   * Test comment rss output.
   */
  public function testRssRow() {
    $this->drupalGet('test-comment-rss');

    // Because the response is XML we can't use the page which depends on an
    // HTML tag being present.
    $result = $this->getSession()->getDriver()->find('//item');
    $this->assertCount(1, $result, 'Just one comment was found in the rss output.');

    $this->assertEqual($result[0]->find('xpath', '//pubDate')->getHtml(), gmdate('r', $this->comment->getCreatedTime()), 'The right pubDate appears in the rss output.');
  }

}
