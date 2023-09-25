<?php

namespace Drupal\KernelTests\Core\Config;

use Drupal\Component\Utility\Html;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigImporterException;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests importing configuration from files into active configuration.
 *
 * @group config
 */
class ConfigImporterTest extends KernelTestBase {

  /**
   * The beginning of an import validation error.
   */
  const FAIL_MESSAGE = 'There were errors validating the config synchronization.';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['config_test', 'system', 'config_import_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'config_test']);
    // Installing config_test's default configuration pollutes the global
    // variable being used for recording hook invocations by this test already,
    // so it has to be cleared out manually.
    unset($GLOBALS['hook_config_test']);

    $this->copyConfig($this->container->get('config.storage'), $this->container->get('config.storage.sync'));
  }

  /**
   * Tests omission of module APIs for bare configuration operations.
   */
  public function testNoImport() {
    $dynamic_name = 'config_test.dynamic.dotted.default';

    // Verify the default configuration values exist.
    $config = $this->config($dynamic_name);
    $this->assertSame('dotted.default', $config->get('id'));

    // Verify that a bare $this->config() does not involve module APIs.
    $this->assertFalse(isset($GLOBALS['hook_config_test']));
  }

  /**
   * Tests that trying to import from empty sync configuration directory fails.
   */
  public function testEmptyImportFails() {
    $this->expectException(ConfigImporterException::class);
    $this->container->get('config.storage.sync')->deleteAll();
    $this->configImporter()->import();
  }

  /**
   * Tests verification of site UUID before importing configuration.
   */
  public function testSiteUuidValidate() {
    $sync = \Drupal::service('config.storage.sync');
    // Create updated configuration object.
    $config_data = $this->config('system.site')->get();
    // Generate a new site UUID.
    $config_data['uuid'] = \Drupal::service('uuid')->generate();
    $sync->write('system.site', $config_data);
    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to mis-matching site UUID.');
    }
    catch (ConfigImporterException $e) {
      $actual_message = $e->getMessage();

      $actual_error_log = $config_importer->getErrors();
      $expected_error_log = ['Site UUID in source storage does not match the target storage.'];
      $this->assertEquals($expected_error_log, $actual_error_log);

      $expected = static::FAIL_MESSAGE . PHP_EOL . 'Site UUID in source storage does not match the target storage.';
      $this->assertEquals($expected, $actual_message);
      foreach ($expected_error_log as $log_row) {
        $this->assertMatchesRegularExpression("/$log_row/", $actual_message);
      }
    }
  }

  /**
   * Tests deletion of configuration during import.
   */
  public function testDeleted() {
    $dynamic_name = 'config_test.dynamic.dotted.default';
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');

    // Verify the default configuration values exist.
    $config = $this->config($dynamic_name);
    $this->assertSame('dotted.default', $config->get('id'));

    // Delete the file from the sync directory.
    $sync->delete($dynamic_name);

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    // Verify the file has been removed.
    $this->assertFalse($storage->read($dynamic_name));

    $config = $this->config($dynamic_name);
    $this->assertNull($config->get('id'));

    // Verify that appropriate module API hooks have been invoked.
    $this->assertTrue(isset($GLOBALS['hook_config_test']['load']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['presave']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['insert']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['update']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['predelete']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['delete']));

    $this->assertFalse($config_importer->hasUnprocessedConfigurationChanges());
    $logs = $config_importer->getErrors();
    $this->assertCount(0, $logs);
  }

  /**
   * Tests creation of configuration during import.
   */
  public function testNew() {
    $dynamic_name = 'config_test.dynamic.new';
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');

    // Verify the configuration to create does not exist yet.
    $this->assertFalse($storage->exists($dynamic_name), $dynamic_name . ' not found.');

    // Create new config entity.
    $original_dynamic_data = [
      'uuid' => '30df59bd-7b03-4cf7-bb35-d42fc49f0651',
      'langcode' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'new',
      'label' => 'New',
      'weight' => 0,
      'style' => '',
      'size' => '',
      'size_value' => '',
      'protected_property' => '',
    ];
    $sync->write($dynamic_name, $original_dynamic_data);

    $this->assertTrue($sync->exists($dynamic_name), $dynamic_name . ' found.');

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    // Verify the values appeared.
    $config = $this->config($dynamic_name);
    $this->assertSame($original_dynamic_data['label'], $config->get('label'));

    // Verify that appropriate module API hooks have been invoked.
    $this->assertFalse(isset($GLOBALS['hook_config_test']['load']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['presave']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['insert']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['update']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['predelete']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['delete']));

    // Verify that hook_config_import_steps_alter() can add steps to
    // configuration synchronization.
    $this->assertTrue(isset($GLOBALS['hook_config_test']['config_import_steps_alter']));

    // Verify that there is nothing more to import.
    $this->assertFalse($config_importer->hasUnprocessedConfigurationChanges());
    $logs = $config_importer->getErrors();
    $this->assertCount(0, $logs);
  }

  /**
   * Tests that secondary writes are overwritten.
   */
  public function testSecondaryWritePrimaryFirst() {
    $name_primary = 'config_test.dynamic.primary';
    $name_secondary = 'config_test.dynamic.secondary';
    $sync = $this->container->get('config.storage.sync');
    $uuid = $this->container->get('uuid');

    $values_primary = [
      'id' => 'primary',
      'label' => 'Primary',
      'weight' => 0,
      'uuid' => $uuid->generate(),
    ];
    $sync->write($name_primary, $values_primary);
    $values_secondary = [
      'id' => 'secondary',
      'label' => 'Secondary Sync',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on primary, to ensure that is synced first.
      'dependencies' => [
        'config' => [$name_primary],
      ],
    ];
    $sync->write($name_secondary, $values_secondary);

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    $entity_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $primary = $entity_storage->load('primary');
    $this->assertEquals('primary', $primary->id());
    $this->assertEquals($values_primary['uuid'], $primary->uuid());
    $this->assertEquals($values_primary['label'], $primary->label());
    $secondary = $entity_storage->load('secondary');
    $this->assertEquals('secondary', $secondary->id());
    $this->assertEquals($values_secondary['uuid'], $secondary->uuid());
    $this->assertEquals($values_secondary['label'], $secondary->label());

    $logs = $config_importer->getErrors();
    $this->assertCount(1, $logs);
    $this->assertEquals(new FormattableMarkup('Deleted and replaced configuration entity "@name"', ['@name' => $name_secondary]), $logs[0]);
  }

  /**
   * Tests that secondary writes are overwritten.
   */
  public function testSecondaryWriteSecondaryFirst() {
    $name_primary = 'config_test.dynamic.primary';
    $name_secondary = 'config_test.dynamic.secondary';
    $sync = $this->container->get('config.storage.sync');
    $uuid = $this->container->get('uuid');

    $values_primary = [
      'id' => 'primary',
      'label' => 'Primary',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on secondary, so that is synced first.
      'dependencies' => [
        'config' => [$name_secondary],
      ],
    ];
    $sync->write($name_primary, $values_primary);
    $values_secondary = [
      'id' => 'secondary',
      'label' => 'Secondary Sync',
      'weight' => 0,
      'uuid' => $uuid->generate(),
    ];
    $sync->write($name_secondary, $values_secondary);

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    $entity_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $primary = $entity_storage->load('primary');
    $this->assertEquals('primary', $primary->id());
    $this->assertEquals($values_primary['uuid'], $primary->uuid());
    $this->assertEquals($values_primary['label'], $primary->label());
    $secondary = $entity_storage->load('secondary');
    $this->assertEquals('secondary', $secondary->id());
    $this->assertEquals($values_secondary['uuid'], $secondary->uuid());
    $this->assertEquals($values_secondary['label'], $secondary->label());

    $logs = $config_importer->getErrors();
    $this->assertCount(1, $logs);
    $this->assertEquals(Html::escape("Unexpected error during import with operation create for {$name_primary}: 'config_test' entity with ID 'secondary' already exists."), $logs[0]);
  }

  /**
   * Tests that secondary updates for deleted files work as expected.
   */
  public function testSecondaryUpdateDeletedDeleterFirst() {
    $name_deleter = 'config_test.dynamic.deleter';
    $name_deletee = 'config_test.dynamic.deletee';
    $name_other = 'config_test.dynamic.other';
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $uuid = $this->container->get('uuid');

    $values_deleter = [
      'id' => 'deleter',
      'label' => 'Deleter',
      'weight' => 0,
      'uuid' => $uuid->generate(),
    ];
    $storage->write($name_deleter, $values_deleter);
    $values_deleter['label'] = 'Updated Deleter';
    $sync->write($name_deleter, $values_deleter);
    $values_deletee = [
      'id' => 'deletee',
      'label' => 'Deletee',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on deleter, to make sure that is synced first.
      'dependencies' => [
        'config' => [$name_deleter],
      ],
    ];
    $storage->write($name_deletee, $values_deletee);
    $values_deletee['label'] = 'Updated Deletee';
    $sync->write($name_deletee, $values_deletee);

    // Ensure that import will continue after the error.
    $values_other = [
      'id' => 'other',
      'label' => 'Other',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on deleter, to make sure that is synced first. This
      // will also be synced after the deletee due to alphabetical ordering.
      'dependencies' => [
        'config' => [$name_deleter],
      ],
    ];
    $storage->write($name_other, $values_other);
    $values_other['label'] = 'Updated other';
    $sync->write($name_other, $values_other);

    // Check update changelist order.
    $config_importer = $this->configImporter();
    $updates = $config_importer->getStorageComparer()->getChangelist('update');
    $expected = [
      $name_deleter,
      $name_deletee,
      $name_other,
    ];
    $this->assertSame($expected, $updates);

    // Import.
    $config_importer->import();

    $entity_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $deleter = $entity_storage->load('deleter');
    $this->assertEquals('deleter', $deleter->id());
    $this->assertEquals($values_deleter['uuid'], $deleter->uuid());
    $this->assertEquals($values_deleter['label'], $deleter->label());

    // The deletee was deleted in
    // \Drupal\config_test\Entity\ConfigTest::postSave().
    $this->assertNull($entity_storage->load('deletee'));

    $other = $entity_storage->load('other');
    $this->assertEquals('other', $other->id());
    $this->assertEquals($values_other['uuid'], $other->uuid());
    $this->assertEquals($values_other['label'], $other->label());

    $logs = $config_importer->getErrors();
    $this->assertCount(1, $logs);
    $this->assertEquals(new FormattableMarkup('Update target "@name" is missing.', ['@name' => $name_deletee]), $logs[0]);
  }

  /**
   * Tests that secondary updates for deleted files work as expected.
   *
   * This test is completely hypothetical since we only support full
   * configuration tree imports. Therefore, any configuration updates that cause
   * secondary deletes should be reflected already in the staged configuration.
   */
  public function testSecondaryUpdateDeletedDeleteeFirst() {
    $name_deleter = 'config_test.dynamic.deleter';
    $name_deletee = 'config_test.dynamic.deletee';
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $uuid = $this->container->get('uuid');

    $values_deleter = [
      'id' => 'deleter',
      'label' => 'Deleter',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on deletee, to make sure that is synced first.
      'dependencies' => [
        'config' => [$name_deletee],
      ],
    ];
    $storage->write($name_deleter, $values_deleter);
    $values_deleter['label'] = 'Updated Deleter';
    $sync->write($name_deleter, $values_deleter);
    $values_deletee = [
      'id' => 'deletee',
      'label' => 'Deletee',
      'weight' => 0,
      'uuid' => $uuid->generate(),
    ];
    $storage->write($name_deletee, $values_deletee);
    $values_deletee['label'] = 'Updated Deletee';
    $sync->write($name_deletee, $values_deletee);

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    $entity_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    // Both entities are deleted. ConfigTest::postSave() causes updates of the
    // deleter entity to delete the deletee entity. Since the deleter depends on
    // the deletee, removing the deletee causes the deleter to be removed.
    $this->assertNull($entity_storage->load('deleter'));
    $this->assertNull($entity_storage->load('deletee'));
    $logs = $config_importer->getErrors();
    $this->assertCount(0, $logs);
  }

  /**
   * Tests that secondary deletes for deleted files work as expected.
   */
  public function testSecondaryDeletedDeleteeSecond() {
    $name_deleter = 'config_test.dynamic.deleter';
    $name_deletee = 'config_test.dynamic.deletee';
    $storage = $this->container->get('config.storage');

    $uuid = $this->container->get('uuid');

    $values_deleter = [
      'id' => 'deleter',
      'label' => 'Deleter',
      'weight' => 0,
      'uuid' => $uuid->generate(),
      // Add a dependency on deletee, to make sure this delete is synced first.
      'dependencies' => [
        'config' => [$name_deletee],
      ],
    ];
    $storage->write($name_deleter, $values_deleter);
    $values_deletee = [
      'id' => 'deletee',
      'label' => 'Deletee',
      'weight' => 0,
      'uuid' => $uuid->generate(),
    ];
    $storage->write($name_deletee, $values_deletee);

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    $entity_storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $this->assertNull($entity_storage->load('deleter'));
    $this->assertNull($entity_storage->load('deletee'));
    // The deletee entity does not exist as the delete worked and although the
    // delete occurred in \Drupal\config_test\Entity\ConfigTest::postDelete()
    // this does not matter.
    $logs = $config_importer->getErrors();
    $this->assertCount(0, $logs);
  }

  /**
   * Tests updating of configuration during import.
   */
  public function testUpdated() {
    $name = 'config_test.system';
    $dynamic_name = 'config_test.dynamic.dotted.default';
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');

    // Verify that the configuration objects to import exist.
    $this->assertTrue($storage->exists($name), $name . ' found.');
    $this->assertTrue($storage->exists($dynamic_name), $dynamic_name . ' found.');

    // Replace the file content of the existing configuration objects in the
    // sync directory.
    $original_name_data = [
      'foo' => 'beer',
    ];
    $sync->write($name, $original_name_data);
    $original_dynamic_data = $storage->read($dynamic_name);
    $original_dynamic_data['label'] = 'Updated';
    $sync->write($dynamic_name, $original_dynamic_data);

    // Verify the active configuration still returns the default values.
    $config = $this->config($name);
    $this->assertSame('bar', $config->get('foo'));
    $config = $this->config($dynamic_name);
    $this->assertSame('Default', $config->get('label'));

    // Import.
    $config_importer = $this->configImporter();
    $config_importer->import();

    // Verify the values were updated.
    \Drupal::configFactory()->reset($name);
    $config = $this->config($name);
    $this->assertSame('beer', $config->get('foo'));
    $config = $this->config($dynamic_name);
    $this->assertSame('Updated', $config->get('label'));

    // Verify that the original file content is still the same.
    $this->assertSame($original_name_data, $sync->read($name));
    $this->assertSame($original_dynamic_data, $sync->read($dynamic_name));

    // Verify that appropriate module API hooks have been invoked.
    $this->assertTrue(isset($GLOBALS['hook_config_test']['load']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['presave']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['insert']));
    $this->assertTrue(isset($GLOBALS['hook_config_test']['update']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['predelete']));
    $this->assertFalse(isset($GLOBALS['hook_config_test']['delete']));

    // Verify that there is nothing more to import.
    $this->assertFalse($config_importer->hasUnprocessedConfigurationChanges());
    $logs = $config_importer->getErrors();
    $this->assertCount(0, $logs);
  }

  /**
   * Tests the isInstallable method()
   */
  public function testIsInstallable() {
    $config_name = 'config_test.dynamic.isinstallable';
    $this->assertFalse($this->container->get('config.storage')->exists($config_name));
    \Drupal::state()->set('config_test.isinstallable', TRUE);
    $this->installConfig(['config_test']);
    $this->assertTrue($this->container->get('config.storage')->exists($config_name));
  }

  /**
   * Tests dependency validation during configuration import.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   * @see \Drupal\Core\Config\ConfigImporter::createExtensionChangelist()
   */
  public function testUnmetDependency() {
    $storage = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');

    // Test an unknown configuration owner.
    $sync->write('unknown.config', ['test' => 'test']);

    // Make a config entity have unmet dependencies.
    $config_entity_data = $sync->read('config_test.dynamic.dotted.default');
    $config_entity_data['dependencies'] = ['module' => ['unknown']];
    $sync->write('config_test.dynamic.dotted.module', $config_entity_data);
    $config_entity_data['dependencies'] = ['theme' => ['unknown']];
    $sync->write('config_test.dynamic.dotted.theme', $config_entity_data);
    $config_entity_data['dependencies'] = ['config' => ['unknown']];
    $sync->write('config_test.dynamic.dotted.config', $config_entity_data);

    // Make an active config depend on something that is missing in sync.
    // The whole configuration needs to be consistent, not only the updated one.
    $config_entity_data['dependencies'] = [];
    $storage->write('config_test.dynamic.dotted.deleted', $config_entity_data);
    $config_entity_data['dependencies'] = ['config' => ['config_test.dynamic.dotted.deleted']];
    $storage->write('config_test.dynamic.dotted.existing', $config_entity_data);
    $sync->write('config_test.dynamic.dotted.existing', $config_entity_data);

    $extensions = $sync->read('core.extension');
    // Add a module and a theme that do not exist.
    $extensions['module']['unknown_module'] = 0;
    $extensions['theme']['unknown_theme'] = 0;
    // Add a module and a theme that depend on uninstalled extensions.
    $extensions['module']['book'] = 0;
    $extensions['theme']['test_subtheme'] = 0;

    $sync->write('core.extension', $extensions);
    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown; an invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $expected = [
        static::FAIL_MESSAGE,
        'Unable to install the <em class="placeholder">unknown_module</em> module since it does not exist.',
        'Unable to install the <em class="placeholder">Book</em> module since it requires the <em class="placeholder">Node, Text, Field, Filter, User</em> modules.',
        'Unable to install the <em class="placeholder">unknown_theme</em> theme since it does not exist.',
        'Unable to install the <em class="placeholder">Theme test subtheme</em> theme since it requires the <em class="placeholder">Theme test base theme</em> theme.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.config</em> depends on the <em class="placeholder">unknown</em> configuration that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.existing</em> depends on the <em class="placeholder">config_test.dynamic.dotted.deleted</em> configuration that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.module</em> depends on the <em class="placeholder">unknown</em> module that will not be installed after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.theme</em> depends on the <em class="placeholder">unknown</em> theme that will not be installed after import.',
        'Configuration <em class="placeholder">unknown.config</em> depends on the <em class="placeholder">unknown</em> extension that will not be installed after import.',
      ];
      $this->assertEquals(implode(PHP_EOL, $expected), $e->getMessage());
      $error_log = $config_importer->getErrors();
      $expected = [
        'Unable to install the <em class="placeholder">unknown_module</em> module since it does not exist.',
        'Unable to install the <em class="placeholder">Book</em> module since it requires the <em class="placeholder">Node, Text, Field, Filter, User</em> modules.',
        'Unable to install the <em class="placeholder">unknown_theme</em> theme since it does not exist.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.config</em> depends on the <em class="placeholder">unknown</em> configuration that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.existing</em> depends on the <em class="placeholder">config_test.dynamic.dotted.deleted</em> configuration that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.module</em> depends on the <em class="placeholder">unknown</em> module that will not be installed after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.theme</em> depends on the <em class="placeholder">unknown</em> theme that will not be installed after import.',
        'Configuration <em class="placeholder">unknown.config</em> depends on the <em class="placeholder">unknown</em> extension that will not be installed after import.',
      ];
      foreach ($expected as $expected_message) {
        $this->assertContainsEquals($expected_message, $error_log, $expected_message);
      }
    }

    // Make a config entity have multiple unmet dependencies.
    $config_entity_data = $sync->read('config_test.dynamic.dotted.default');
    $config_entity_data['dependencies'] = ['module' => ['unknown', 'dblog']];
    $sync->write('config_test.dynamic.dotted.module', $config_entity_data);
    $config_entity_data['dependencies'] = ['theme' => ['unknown', 'stark']];
    $sync->write('config_test.dynamic.dotted.theme', $config_entity_data);
    $config_entity_data['dependencies'] = ['config' => ['unknown', 'unknown2']];
    $sync->write('config_test.dynamic.dotted.config', $config_entity_data);
    $config_importer = $this->configImporter();
    try {
      $this->configImporter->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $expected = [
        static::FAIL_MESSAGE,
        'Unable to install the <em class="placeholder">unknown_module</em> module since it does not exist.',
        'Unable to install the <em class="placeholder">Book</em> module since it requires the <em class="placeholder">Node, Text, Field, Filter, User</em> modules.',
        'Unable to install the <em class="placeholder">unknown_theme</em> theme since it does not exist.',
        'Unable to install the <em class="placeholder">Theme test subtheme</em> theme since it requires the <em class="placeholder">Theme test base theme</em> theme.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.config</em> depends on configuration (<em class="placeholder">unknown, unknown2</em>) that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.existing</em> depends on the <em class="placeholder">config_test.dynamic.dotted.deleted</em> configuration that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.module</em> depends on modules (<em class="placeholder">unknown, Database Logging</em>) that will not be installed after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.theme</em> depends on themes (<em class="placeholder">unknown, Stark</em>) that will not be installed after import.',
        'Configuration <em class="placeholder">unknown.config</em> depends on the <em class="placeholder">unknown</em> extension that will not be installed after import.',
      ];
      $this->assertEquals(implode(PHP_EOL, $expected), $e->getMessage());
      $error_log = $config_importer->getErrors();
      $expected = [
        'Configuration <em class="placeholder">config_test.dynamic.dotted.config</em> depends on configuration (<em class="placeholder">unknown, unknown2</em>) that will not exist after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.module</em> depends on modules (<em class="placeholder">unknown, Database Logging</em>) that will not be installed after import.',
        'Configuration <em class="placeholder">config_test.dynamic.dotted.theme</em> depends on themes (<em class="placeholder">unknown, Stark</em>) that will not be installed after import.',
      ];
      foreach ($expected as $expected_message) {
        $this->assertContainsEquals($expected_message, $error_log, $expected_message);
      }
    }
  }

  /**
   * Tests missing core.extension during configuration import.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   */
  public function testMissingCoreExtension() {
    $sync = $this->container->get('config.storage.sync');
    $sync->delete('core.extension');
    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $expected = static::FAIL_MESSAGE . PHP_EOL . 'The core.extension configuration does not exist.';
      $this->assertEquals($expected, $e->getMessage());
      $error_log = $config_importer->getErrors();
      $this->assertEquals(['The core.extension configuration does not exist.'], $error_log);
    }
  }

  /**
   * Tests uninstall validators being called during synchronization.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   */
  public function testRequiredModuleValidation() {
    $sync = $this->container->get('config.storage.sync');

    $extensions = $sync->read('core.extension');
    unset($extensions['module']['system']);
    $sync->write('core.extension', $extensions);

    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown, invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $this->assertStringContainsString('There were errors validating the config synchronization.', $e->getMessage());
      $error_log = $config_importer->getErrors();
      $this->assertEquals('Unable to uninstall the System module because: The System module is required.', $error_log[0]);
    }
  }

  /**
   * Tests installing a base themes and sub themes during configuration import.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   */
  public function testInstallBaseAndSubThemes() {
    $sync = $this->container->get('config.storage.sync');
    $extensions = $sync->read('core.extension');
    $extensions['theme']['test_basetheme'] = 0;
    $extensions['theme']['test_subtheme'] = 0;
    $extensions['theme']['test_subsubtheme'] = 0;
    $sync->write('core.extension', $extensions);
    $config_importer = $this->configImporter();
    $config_importer->import();
    $this->assertTrue($this->container->get('theme_handler')->themeExists('test_basetheme'));
    $this->assertTrue($this->container->get('theme_handler')->themeExists('test_subsubtheme'));
    $this->assertTrue($this->container->get('theme_handler')->themeExists('test_subtheme'));

    // Test uninstalling them.
    $extensions = $sync->read('core.extension');
    unset($extensions['theme']['test_basetheme']);
    unset($extensions['theme']['test_subsubtheme']);
    unset($extensions['theme']['test_subtheme']);
    $sync->write('core.extension', $extensions);
    $config_importer = $this->configImporter();
    $config_importer->import();
    $this->assertFalse($this->container->get('theme_handler')->themeExists('test_basetheme'));
    $this->assertFalse($this->container->get('theme_handler')->themeExists('test_subsubtheme'));
    $this->assertFalse($this->container->get('theme_handler')->themeExists('test_subtheme'));
  }

  /**
   * Tests install profile validation during configuration import.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   */
  public function testInstallProfile() {
    $sync = $this->container->get('config.storage.sync');

    $extensions = $sync->read('core.extension');
    // Add an install profile.
    $extensions['module']['standard'] = 0;

    $sync->write('core.extension', $extensions);
    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown; an invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $expected = static::FAIL_MESSAGE . PHP_EOL . 'Unable to install the <em class="placeholder">standard</em> module since it does not exist.';
      $this->assertEquals($expected, $e->getMessage(), 'There were errors validating the config synchronization.');
      $error_log = $config_importer->getErrors();
      // Install profiles should not even be scanned at this point.
      $this->assertEquals(['Unable to install the standard module since it does not exist.'], $error_log);
    }
  }

  /**
   * Tests install profile validation during configuration import.
   *
   * @see \Drupal\Core\EventSubscriber\ConfigImportSubscriber
   */
  public function testInstallProfileMisMatch() {
    $sync = $this->container->get('config.storage.sync');

    $extensions = $sync->read('core.extension');
    // Change the install profile.
    $extensions['profile'] = 'this_will_not_work';
    $sync->write('core.extension', $extensions);

    $config_importer = $this->configImporter();
    try {
      $config_importer->import();
      $this->fail('ConfigImporterException not thrown; an invalid import was not stopped due to missing dependencies.');
    }
    catch (ConfigImporterException $e) {
      $expected = static::FAIL_MESSAGE . PHP_EOL . 'Cannot change the install profile from <em class="placeholder"></em> to <em class="placeholder">this_will_not_work</em> once Drupal is installed.';
      $this->assertEquals($expected, $e->getMessage(), 'There were errors validating the config synchronization.');
      $error_log = $config_importer->getErrors();
      // Install profiles can not be changed. Note that KernelTestBase currently
      // does not use an install profile. This situation should be impossible
      // to get in but site's can removed the install profile setting from
      // settings.php so the test is valid.
      $this->assertEquals(['Cannot change the install profile from  to this_will_not_work once Drupal is installed.'], $error_log);
    }
  }

  /**
   * Tests the isSyncing flags.
   */
  public function testIsSyncingInHooks() {
    $dynamic_name = 'config_test.dynamic.dotted.default';
    $storage = $this->container->get('config.storage');

    // Verify the default configuration values exist.
    $config = $this->config($dynamic_name);
    $this->assertSame('dotted.default', $config->get('id'));

    // Delete the config so that create hooks will fire.
    $storage->delete($dynamic_name);
    \Drupal::state()->set('config_test.store_isSyncing', []);
    $this->configImporter()->import();

    // The values of the syncing values should be stored in state by
    // config_test_config_test_create().
    $state = \Drupal::state()->get('config_test.store_isSyncing');
    $this->assertTrue($state['global_state::create'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::create'], 'ConfigEntity::isSyncing() returns TRUE');
    $this->assertTrue($state['global_state::presave'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::presave'], 'ConfigEntity::isSyncing() returns TRUE');
    $this->assertTrue($state['global_state::insert'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::insert'], 'ConfigEntity::isSyncing() returns TRUE');

    // Cause a config update so update hooks will fire.
    $config = $this->config($dynamic_name);
    $config->set('label', 'A new name')->save();
    \Drupal::state()->set('config_test.store_isSyncing', []);
    $this->configImporter()->import();

    // The values of the syncing values should be stored in state by
    // config_test_config_test_create().
    $state = \Drupal::state()->get('config_test.store_isSyncing');
    $this->assertTrue($state['global_state::presave'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::presave'], 'ConfigEntity::isSyncing() returns TRUE');
    $this->assertTrue($state['global_state::update'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::update'], 'ConfigEntity::isSyncing() returns TRUE');

    // Cause a config delete so delete hooks will fire.
    $sync = $this->container->get('config.storage.sync');
    $sync->delete($dynamic_name);
    \Drupal::state()->set('config_test.store_isSyncing', []);
    $this->configImporter()->import();

    // The values of the syncing values should be stored in state by
    // config_test_config_test_create().
    $state = \Drupal::state()->get('config_test.store_isSyncing');
    $this->assertTrue($state['global_state::predelete'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::predelete'], 'ConfigEntity::isSyncing() returns TRUE');
    $this->assertTrue($state['global_state::delete'], '\Drupal::isConfigSyncing() returns TRUE');
    $this->assertTrue($state['entity_state::delete'], 'ConfigEntity::isSyncing() returns TRUE');
  }

  /**
   * Tests that the isConfigSyncing flag is cleanup after an invalid step.
   */
  public function testInvalidStep() {
    $this->assertFalse(\Drupal::isConfigSyncing(), 'Before an import \Drupal::isConfigSyncing() returns FALSE');
    $context = [];
    $config_importer = $this->configImporter();
    try {
      $config_importer->doSyncStep('a_non_existent_step', $context);
      $this->fail('Expected \InvalidArgumentException thrown');
    }
    catch (\InvalidArgumentException $e) {
      // Expected exception; just continue testing.
    }
    $this->assertFalse(\Drupal::isConfigSyncing(), 'After an invalid step \Drupal::isConfigSyncing() returns FALSE');
  }

  /**
   * Tests that the isConfigSyncing flag is set correctly during a custom step.
   */
  public function testCustomStep() {
    $this->assertFalse(\Drupal::isConfigSyncing(), 'Before an import \Drupal::isConfigSyncing() returns FALSE');
    $context = [];
    $this->configImporter()->doSyncStep(self::customStep(...), $context);
    $this->assertTrue($context['is_syncing'], 'Inside a custom step \Drupal::isConfigSyncing() returns TRUE');
    $this->assertFalse(\Drupal::isConfigSyncing(), 'After an valid custom step \Drupal::isConfigSyncing() returns FALSE');
  }

  /**
   * Tests that uninstall a theme in config import correctly imports all config.
   */
  public function testUninstallThemeIncrementsCount(): void {
    $theme_installer = $this->container->get('theme_installer');
    // Install our theme.
    $theme = 'test_basetheme';
    $theme_installer->install([$theme]);

    $this->assertTrue($this->container->get('theme_handler')->themeExists($theme));

    $sync = $this->container->get('config.storage.sync');

    // Update 2 pieces of config in sync.
    $systemSiteName = 'system.site';
    $system = $sync->read($systemSiteName);
    $system['name'] = 'Foo';
    $sync->write($systemSiteName, $system);

    $cronName = 'system.cron';
    $cron = $sync->read($cronName);
    $this->assertEquals(1, $cron['logging']);
    $cron['logging'] = 0;
    $sync->write($cronName, $cron);

    // Uninstall the theme in sync.
    $extensions = $sync->read('core.extension');
    unset($extensions['theme'][$theme]);
    $sync->write('core.extension', $extensions);

    $this->configImporter()->import();

    // The theme should be uninstalled.
    $this->assertFalse($this->container->get('theme_handler')->themeExists($theme));

    // Both pieces of config should be updated.
    \Drupal::configFactory()->reset($systemSiteName);
    \Drupal::configFactory()->reset($cronName);
    $this->assertEquals('Foo', $this->config($systemSiteName)->get('name'));
    $this->assertEquals(0, $this->config($cronName)->get('logging'));
  }

  /**
   * Helper method to test custom config installer steps.
   *
   * @param array $context
   *   Batch context.
   * @param \Drupal\Core\Config\ConfigImporter $importer
   *   The config importer.
   */
  public static function customStep(array &$context, ConfigImporter $importer) {
    $context['is_syncing'] = \Drupal::isConfigSyncing();
  }

}
