<?php

namespace Drupal\Tests\migrate\Unit\process;

use Drupal\migrate\MigrateSkipProcessException;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\process\MigrationLookup;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Prophecy\Argument;

/**
 * @coversDefaultClass \Drupal\migrate\Plugin\migrate\process\MigrationLookup
 * @group migrate
 */
class MigrationLookupTest extends MigrationLookupTestCase {

  /**
   * @covers ::transform
   */
  public function testTransformWithStubSkipping() {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $migration_plugin_manager = $this->prophesize(MigrationPluginManagerInterface::class);

    $destination_id_map = $this->prophesize(MigrateIdMapInterface::class);
    $destination_migration = $this->prophesize(MigrationInterface::class);
    $destination_migration->getIdMap()->willReturn($destination_id_map->reveal());
    $destination_id_map->lookupDestinationIds([1])->willReturn(NULL);

    // Ensure the migration plugin manager returns our migration.
    $migration_plugin_manager->createInstances(Argument::exact(['destination_migration']))
      ->willReturn(['destination_migration' => $destination_migration->reveal()]);

    $configuration = [
      'no_stub' => TRUE,
      'migration' => 'destination_migration',
    ];

    $migration_plugin->id()->willReturn('actual_migration');
    $destination_migration->getDestinationPlugin(TRUE)->shouldNotBeCalled();

    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $result = $migration->transform(1, $this->migrateExecutable, $this->row, '');
    $this->assertNull($result);
  }

  /**
   * @covers ::transform
   */
  public function testTransformWithStubbing() {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $this->migrateLookup->lookup('destination_migration', [1])->willReturn(NULL);
    $this->migrateStub->createStub('destination_migration', [1], [], FALSE)->willReturn([2]);

    $configuration = [
      'no_stub' => FALSE,
      'migration' => 'destination_migration',
    ];

    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $result = $migration->transform(1, $this->migrateExecutable, $this->row, '');
    $this->assertEquals(2, $result);
  }

  /**
   * Tests that processing is skipped when the input value is invalid.
   *
   * @param mixed $value
   *   An invalid value.
   *
   * @dataProvider skipInvalidDataProvider
   */
  public function testSkipInvalid($value) {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $migration_plugin_manager = $this->prophesize(MigrationPluginManagerInterface::class);

    $configuration = [
      'migration' => 'foobaz',
    ];
    $migration_plugin->id()->willReturn(uniqid());
    $migration_plugin_manager->createInstances(['foobaz'])
      ->willReturn(['foobaz' => $migration_plugin->reveal()]);
    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $this->expectException(MigrateSkipProcessException::class);
    $migration->transform($value, $this->migrateExecutable, $this->row, 'foo');
  }

  /**
   * Provides data for the SkipInvalid test.
   *
   * @return array
   *   Empty values.
   */
  public function skipInvalidDataProvider() {
    return [
      'Empty String' => [''],
      'Boolean False' => [FALSE],
      'Empty Array' => [[]],
      'Null' => [NULL],
    ];
  }

  /**
   * Test that valid, but technically empty values are not skipped.
   *
   * @param mixed $value
   *   A valid value.
   *
   * @dataProvider noSkipValidDataProvider
   */
  public function testNoSkipValid($value) {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $migration_plugin_manager = $this->prophesize(MigrationPluginManagerInterface::class);
    $process_plugin_manager = $this->prophesize(MigratePluginManager::class);
    $id_map = $this->prophesize(MigrateIdMapInterface::class);
    $id_map->lookupDestinationIds([$value])->willReturn([]);
    $migration_plugin->getIdMap()->willReturn($id_map->reveal());

    $configuration = [
      'migration' => 'foobaz',
      'no_stub' => TRUE,
    ];
    $migration_plugin->id()->willReturn(uniqid());
    $migration_plugin_manager->createInstances(['foobaz'])
      ->willReturn(['foobaz' => $migration_plugin->reveal()]);
    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $lookup = $migration->transform($value, $this->migrateExecutable, $this->row, 'foo');

    /* We provided no values and asked for no stub, so we should get NULL. */
    $this->assertNull($lookup);
  }

  /**
   * Provides data for the NoSkipValid test.
   *
   * @return array
   *   Empty values.
   */
  public function noSkipValidDataProvider() {
    return [
      'Integer Zero' => [0],
      'String Zero' => ['0'],
      'Float Zero' => [0.0],
    ];
  }

  /**
   * Tests a successful lookup.
   *
   * @param array $source_id_values
   *   The source id(s) of the migration map.
   * @param array $destination_id_values
   *   The destination id(s) of the migration map.
   * @param string|array $source_value
   *   The source value(s) for the migration process plugin.
   * @param string|array $expected_value
   *   The expected value(s) of the migration process plugin.
   *
   * @dataProvider successfulLookupDataProvider
   *
   * @throws \Drupal\migrate\MigrateSkipProcessException
   */
  public function testSuccessfulLookup(array $source_id_values, array $destination_id_values, $source_value, $expected_value) {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $this->migrateLookup->lookup('foobaz', $source_id_values)->willReturn([$destination_id_values]);

    $configuration = [
      'migration' => 'foobaz',
    ];

    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $this->assertSame($expected_value, $migration->transform($source_value, $this->migrateExecutable, $this->row, 'foo'));
  }

  /**
   * Provides data for the successful lookup test.
   *
   * @return array
   *   The data.
   */
  public function successfulLookupDataProvider() {
    return [
      // Test data for scalar to scalar.
      [
        // Source ID of the migration map.
        [1],
        // Destination ID of the migration map.
        [3],
        // Input value for the migration plugin.
        1,
        // Expected output value of the migration plugin.
        3,
      ],
      // Test 0 as data source ID.
      [
        // Source ID of the migration map.
        [0],
        // Destination ID of the migration map.
        [3],
        // Input value for the migration plugin.
        0,
        // Expected output value of the migration plugin.
        3,
      ],
      // Test data for scalar to array.
      [
        // Source ID of the migration map.
        [1],
        // Destination IDs of the migration map.
        [3, 'foo'],
        // Input value for the migration plugin.
        1,
        // Expected output values of the migration plugin.
        [3, 'foo'],
      ],
      // Test data for array to scalar.
      [
        // Source IDs of the migration map.
        [1, 3],
        // Destination ID of the migration map.
        ['foo'],
        // Input values for the migration plugin.
        [1, 3],
        // Expected output value of the migration plugin.
        'foo',
      ],
      // Test data for array to array.
      [
        // Source IDs of the migration map.
        [1, 3],
        // Destination IDs of the migration map.
        [3, 'foo'],
        // Input values for the migration plugin.
        [1, 3],
        // Expected output values of the migration plugin.
        [3, 'foo'],
      ],
    ];
  }

  /**
   * Tests processing multiple source IDs.
   */
  public function testMultipleSourceIds() {
    $migration_plugin = $this->prophesize(MigrationInterface::class);
    $this->migrateLookup->lookup('foobaz', ['id', 6])->willReturn([[2]]);
    $configuration = [
      'migration' => 'foobaz',
    ];
    $migration = MigrationLookup::create($this->prepareContainer(), $configuration, '', [], $migration_plugin->reveal());
    $result = $migration->transform(['id', 6], $this->migrateExecutable, $this->row, '');
    $this->assertEquals(2, $result);
  }

}
