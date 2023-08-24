<?php

namespace Drupal\FunctionalTests\Installer;

use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the installer with an existing settings file.
 *
 * @group Installer
 */
class InstallerExistingSettingsTest extends InstallerTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * Fully configures a preexisting settings.php file before invoking the
   * interactive installer.
   */
  protected function prepareEnvironment() {
    parent::prepareEnvironment();
    // Pre-configure hash salt.
    // Any string is valid, so simply use the class name of this test.
    $this->settings['settings']['hash_salt'] = (object) [
      'value' => __CLASS__,
      'required' => TRUE,
    ];

    // Pre-configure database credentials.
    $connection_info = Database::getConnectionInfo();
    unset($connection_info['default']['pdo']);
    unset($connection_info['default']['init_commands']);

    $this->settings['databases']['default'] = (object) [
      'value' => $connection_info,
      'required' => TRUE,
    ];

    // Use the kernel to find the site path because the site.path service should
    // not be available at this point in the install process.
    $site_path = DrupalKernel::findSitePath(Request::createFromGlobals());
    // Pre-configure config directories.
    $this->settings['settings']['config_sync_directory'] = (object) [
      'value' => $site_path . '/files/config_sync',
      'required' => TRUE,
    ];
    mkdir($this->settings['settings']['config_sync_directory']->value, 0777, TRUE);
  }

  /**
   * Visits the interactive installer.
   */
  protected function visitInstaller() {
    // Should redirect to the installer.
    $this->drupalGet($GLOBALS['base_url']);
    // Ensure no database tables have been created yet.
    $this->assertSame([], Database::getConnection()->schema()->findTables('%'));
    $this->assertSession()->addressEquals($GLOBALS['base_url'] . '/core/install.php');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpSettings() {
    // This step should not appear, since settings.php is fully configured
    // already.
  }

  /**
   * Verifies that installation succeeded.
   */
  public function testInstaller() {
    $this->assertUrl('user/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertEqual('testing', \Drupal::installProfile());
  }

}
