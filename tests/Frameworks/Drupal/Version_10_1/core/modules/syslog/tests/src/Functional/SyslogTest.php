<?php

namespace Drupal\Tests\syslog\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests syslog settings.
 *
 * @group syslog
 */
class SyslogTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['syslog'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the syslog settings page.
   */
  public function testSettings() {
    $admin_user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin_user);

    // If we're on Windows, there is no configuration form.
    if (defined('LOG_LOCAL6')) {
      $this->drupalGet('admin/config/development/logging');
      $this->submitForm(['syslog_facility' => LOG_LOCAL6], 'Save configuration');
      $this->assertSession()->pageTextContains('The configuration options have been saved.');

      $this->drupalGet('admin/config/development/logging');
      // Should be one field.
      $field = $this->assertSession()->elementExists('xpath', '//option[@value="' . LOG_LOCAL6 . '"]');
      $this->assertSame('selected', $field->getAttribute('selected'), 'Facility value saved.');
    }
  }

}
