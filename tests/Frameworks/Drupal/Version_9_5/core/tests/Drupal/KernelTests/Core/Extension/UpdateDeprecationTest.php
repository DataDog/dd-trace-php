<?php

namespace Drupal\KernelTests\Core\Extension;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests deprecated update.inc functions.
 *
 * @group legacy
 * @group extension
 *
 * @todo Remove in https://www.drupal.org/node/3210931
 */
class UpdateDeprecationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Include the legacy update.inc file.
    include_once $this->root . '/core/includes/update.inc';
  }

  /**
   * Tests update_check_incompatibility() function.
   */
  public function testUpdateCheckIncompatibility() {
    $this->expectDeprecation('update_check_incompatibility() is deprecated in drupal:9.1.0 and is removed from drupal:10.0.0. No direct replacement is provided. See https://www.drupal.org/node/3150727');
    $this->assertTrue(update_check_incompatibility('incompatible_module'));
    $this->assertFalse(update_check_incompatibility('system'));
  }

  /**
   * Tests update_set_schema() function.
   */
  public function testUpdateSetSchema() {
    $this->expectDeprecation('update_set_schema() is deprecated in drupal:9.2.0 and is removed from drupal:10.0.0. No direct replacement is provided. See https://www.drupal.org/node/3210925');
    update_set_schema('update_test_schema', 8003);
    // Ensure schema has changed.
    $this->assertEquals(8003, \Drupal::keyValue('system.schema')->get('update_test_schema'));
  }

  /**
   * Deprecation testing for drupal_get_schema_versions function.
   *
   * @see drupal_get_schema_versions()
   */
  public function testDrupalGetSchemaVersionsLegacyTest() {
    $this->expectDeprecation('drupal_get_schema_versions() is deprecated in drupal:9.3.0 and is removed from drupal:10.0.0. Use \Drupal\Core\Update\UpdateHookRegistry::getAvailableUpdates() instead. See https://www.drupal.org/node/2444417');
    $this->assertEmpty(drupal_get_schema_versions('update_test_schema'));
  }

  /**
   * Deprecation testing for drupal installed schema version functions.
   *
   * @see drupal_get_installed_schema_version()
   * @see drupal_set_installed_schema_version()
   */
  public function testDrupalGetInstalledSchemaVersion() {
    $this->expectDeprecation('drupal_get_installed_schema_version() is deprecated in drupal:9.3.0 and is removed from drupal:10.0.0. Use \Drupal\Core\Update\UpdateHookRegistry::getInstalledVersion() or \Drupal\Core\Update\UpdateHookRegistry::getAllInstalledVersions() instead. See https://www.drupal.org/node/2444417');
    $this->assertIsArray(drupal_get_installed_schema_version(NULL, TRUE, TRUE));
    $this->expectDeprecation('drupal_set_installed_schema_version() is deprecated in drupal:9.3.0 and is removed from drupal:10.0.0. Use \Drupal\Core\Update\UpdateHookRegistry::setInstalledVersion() instead. See https://www.drupal.org/node/2444417');
    drupal_set_installed_schema_version('system', 8001);
  }

}
