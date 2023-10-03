<?php

namespace Drupal\Tests\statistics\Functional\Views;

use Drupal\Tests\views\Functional\ViewTestBase;
use Drupal\views\Tests\ViewTestData;

/**
 * Tests basic integration of views data from the statistics module.
 *
 * @group statistics
 * @see
 */
class IntegrationTest extends ViewTestBase {


  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['statistics', 'statistics_test_views', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Stores the user object that accesses the page.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $webUser;

  /**
   * Stores the node object which is used by the test.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $node;

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_statistics_integration'];

  protected function setUp($import_test_views = TRUE) {
    parent::setUp($import_test_views);

    ViewTestData::createTestViews(get_class($this), ['statistics_test_views']);

    // Create a new user for viewing nodes and statistics.
    $this->webUser = $this->drupalCreateUser([
      'access content',
      'view post access counter',
    ]);

    // Create a new user for viewing nodes only.
    $this->deniedUser = $this->drupalCreateUser(['access content']);

    $this->drupalCreateContentType(['type' => 'page']);
    $this->node = $this->drupalCreateNode(['type' => 'page']);

    // Enable counting of content views.
    $this->config('statistics.settings')
      ->set('count_content_views', 1)
      ->save();

  }

  /**
   * Tests the integration of the {node_counter} table in views.
   */
  public function testNodeCounterIntegration() {
    $this->drupalLogin($this->webUser);

    $this->drupalGet('node/' . $this->node->id());
    // Manually calling statistics.php, simulating ajax behavior.
    // @see \Drupal\statistics\Tests\StatisticsLoggingTest::testLogging().
    global $base_url;
    $stats_path = $base_url . '/' . drupal_get_path('module', 'statistics') . '/statistics.php';
    $client = $this->getHttpClient();
    $client->post($stats_path, ['form_params' => ['nid' => $this->node->id()]]);
    $this->drupalGet('test_statistics_integration');

    /** @var \Drupal\statistics\StatisticsViewsResult $statistics */
    $statistics = \Drupal::service('statistics.storage.node')->fetchView($this->node->id());
    $this->assertSession()->pageTextContains('Total views: 1');
    $this->assertSession()->pageTextContains('Views today: 1');
    $this->assertSession()->pageTextContains('Most recent view: ' . date('Y', $statistics->getTimestamp()));

    $this->drupalLogout();
    $this->drupalLogin($this->deniedUser);
    $this->drupalGet('test_statistics_integration');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->pageTextNotContains('Total views:');
    $this->assertSession()->pageTextNotContains('Views today:');
    $this->assertSession()->pageTextNotContains('Most recent view:');
  }

}
