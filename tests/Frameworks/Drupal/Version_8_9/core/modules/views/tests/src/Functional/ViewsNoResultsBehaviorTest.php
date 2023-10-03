<?php

namespace Drupal\Tests\views\Functional;

/**
 * Tests no results behavior.
 *
 * @group views
 */
class ViewsNoResultsBehaviorTest extends ViewTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['node', 'user'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE) {
    parent::setUp();
    $this->enableViewsTestModule();
    $user = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($user);

    // Set the Stark theme and use the default templates from views module.
    /** @var \Drupal\Core\Extension\ThemeInstallerInterface $theme_installer */
    $theme_installer = \Drupal::service('theme_installer');
    $theme_installer->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
  }

  /**
   * Tests the view with the text.
   */
  public function testDuplicateText() {
    $output = $this->drupalGet('admin/content');
    $this->assertEqual(1, substr_count($output, 'No content available.'), 'Only one message should be present');
  }

}
