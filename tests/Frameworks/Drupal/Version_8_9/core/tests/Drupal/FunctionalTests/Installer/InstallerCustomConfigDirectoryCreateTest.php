<?php

namespace Drupal\FunctionalTests\Installer;

use Drupal\Core\Site\Settings;

/**
 * Tests the installer when a custom config_directory set up but does not exist.
 *
 * @group Installer
 * @group legacy
 */
class InstallerCustomConfigDirectoryCreateTest extends InstallerTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function prepareEnvironment() {
    parent::prepareEnvironment();
    // Other directories will be created too.
    // This is legacy functionality.
    $this->settings['config_directories']['custom'] = (object) [
      'value' => $this->publicFilesDirectory . '/config_custom',
      'required' => TRUE,
    ];
  }

  /**
   * Verifies that installation succeeded.
   *
   * @expectedDeprecation Automatic creation of 'custom' configuration directory will be removed from drupal:9.0.0. See https://www.drupal.org/node/3018145.
   */
  public function testInstaller() {
    $this->assertUrl('user/1');
    $this->assertResponse(200);
    $this->assertDirectoryExists($this->publicFilesDirectory . '/config_custom');

    // Ensure the sync directory also exists.
    $sync_directory = Settings::get('config_sync_directory');
    $this->assertDirectoryExists($sync_directory);
  }

}
