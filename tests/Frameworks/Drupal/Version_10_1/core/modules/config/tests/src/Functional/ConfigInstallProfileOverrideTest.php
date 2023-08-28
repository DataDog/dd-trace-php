<?php

namespace Drupal\Tests\config\Functional;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Config\InstallStorage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Config\FileStorage;
use Drupal\system\Entity\Action;
use Drupal\tour\Entity\Tour;
use Drupal\user\Entity\Role;

/**
 * Tests that configuration objects are correct after various operations.
 *
 * The installation and removal of configuration objects in install, disable
 * and uninstall functionality is tested.
 *
 * @group config
 */
class ConfigInstallProfileOverrideTest extends BrowserTestBase {

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'testing_config_overrides';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests install profile config changes.
   */
  public function testInstallProfileConfigOverwrite() {
    $config_name = 'system.cron';
    // The expected configuration from the system module.
    $expected_original_data = [
      'threshold' => [
        'requirements_warning' => 172800,
        'requirements_error' => 1209600,
      ],
      'logging' => 1,
    ];
    // The expected active configuration altered by the install profile.
    $expected_profile_data = [
      'threshold' => [
        'requirements_warning' => 259200,
        'requirements_error' => 1209600,
      ],
      'logging' => 1,
    ];
    $expected_profile_data = ['_core' => ['default_config_hash' => Crypt::hashBase64(serialize($expected_profile_data))]] + $expected_profile_data;

    // Verify that the original data matches. We have to read the module config
    // file directly, because the install profile default system.cron.yml
    // configuration file was used to create the active configuration.
    $config_dir = $this->getModulePath('system') . '/' . InstallStorage::CONFIG_INSTALL_DIRECTORY;
    $this->assertDirectoryExists($config_dir);
    $source_storage = new FileStorage($config_dir);
    $data = $source_storage->read($config_name);
    $this->assertSame($expected_original_data, $data);

    // Verify that active configuration matches the expected data, which was
    // created from the testing install profile's system.cron.yml file.
    $config = $this->config($config_name);
    $this->assertSame($expected_profile_data, $config->get());

    $config = $this->config('system.site');
    // Verify the system.site config has a valid UUID.
    $this->assertTrue(Uuid::isValid($config->get('uuid')));
    // Verify the profile overrides have been used.
    $this->assertEquals(91, $config->get('weight_select_max'));
    // Ensure the site configure form is used.
    $this->assertEquals('Drupal', $config->get('name'));
    $this->assertEquals('simpletest@example.com', $config->get('mail'));

    // Ensure that the configuration entity has the expected dependencies and
    // overrides.
    $action = Action::load('user_block_user_action');
    $this->assertEquals('Overridden block the selected user(s)', $action->label());
    $action = Action::load('user_cancel_user_action');
    $this->assertEquals('Cancel the selected user account(s)', $action->label(), 'Default configuration that is not overridden is not affected.');

    // Ensure that optional configuration can be overridden.
    $tour = Tour::load('language');
    $this->assertCount(1, $tour->getTips(), 'Optional configuration can be overridden. The language tour only has one tip');
    $tour = Tour::load('language-add');
    $this->assertCount(3, $tour->getTips(), 'Optional configuration that is not overridden is not affected.');

    // Ensure the optional configuration is installed. Note that the overridden
    // language tour has a dependency on this tour so it has to exist.
    $this->assertInstanceOf(Tour::class, Tour::load('testing_config_overrides_module'));

    // Ensure that optional configuration from a profile is created if
    // dependencies are met.
    $this->assertEquals('Config override test', Tour::load('testing_config_overrides')->label());

    // Ensure that optional configuration from a profile is not created if
    // dependencies are not met. Cannot use the entity system since the entity
    // type does not exist.
    $optional_dir = $this->getModulePath('testing_config_overrides') . '/' . InstallStorage::CONFIG_OPTIONAL_DIRECTORY;
    $optional_storage = new FileStorage($optional_dir);
    foreach (['config_test.dynamic.dotted.default', 'config_test.dynamic.override', 'config_test.dynamic.override_unmet'] as $id) {
      $this->assertTrue(\Drupal::config($id)->isNew(), "The config_test entity $id contained in the profile's optional directory does not exist.");
      // Make that we don't get false positives from the assertion above.
      $this->assertTrue($optional_storage->exists($id), "The config_test entity $id does exist in the profile's optional directory.");
    }

    // Install the config_test module and ensure that the override from the
    // install profile is used. Optional configuration can override
    // configuration in a modules config/install directory.
    $this->container->get('module_installer')->install(['config_test']);
    $this->rebuildContainer();
    $config_test_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $this->assertEquals('Default install profile override', $config_test_storage->load('dotted.default')->label(), 'The config_test entity is overridden by the profile optional configuration.');
    // Test that override of optional configuration does work.
    $this->assertEquals('Override', $config_test_storage->load('override')->label(), 'The optional config_test entity is overridden by the profile optional configuration.');
    // Test that override of optional configuration which introduces an unmet
    // dependency does not get created.
    $this->assertNull($config_test_storage->load('override_unmet'), 'The optional config_test entity with unmet dependencies is not created.');
    $this->assertNull($config_test_storage->load('completely_new'), 'The completely new optional config_test entity with unmet dependencies is not created.');

    // Installing dblog creates the optional configuration.
    $this->container->get('module_installer')->install(['dblog']);
    $this->rebuildContainer();
    $this->assertEquals('Override', $config_test_storage->load('override_unmet')->label(), 'The optional config_test entity is overridden by the profile optional configuration and is installed when its dependencies are met.');
    $config_test_new = $config_test_storage->load('completely_new');
    $this->assertEquals('Completely new optional configuration', $config_test_new->label(), 'The optional config_test entity is provided by the profile optional configuration and is installed when its dependencies are met.');
    $config_test_new->delete();

    // Install another module that provides optional configuration and ensure
    // that deleted profile configuration is not re-created.
    $this->container->get('module_installer')->install(['config_other_module_config_test']);
    $this->rebuildContainer();
    $config_test_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $this->assertNull($config_test_storage->load('completely_new'));

    // Ensure the authenticated role has the access tour permission.
    $role = Role::load(Role::AUTHENTICATED_ID);
    $this->assertTrue($role->hasPermission('access tour'), 'The Authenticated role has the "access tour" permission.');
    $this->assertEquals(['module' => ['tour']], $role->getDependencies());
  }

}
