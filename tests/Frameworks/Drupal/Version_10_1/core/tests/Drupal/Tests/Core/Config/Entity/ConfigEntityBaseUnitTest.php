<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\Config\Entity\ConfigEntityBaseUnitTest.
 */

namespace Drupal\Tests\Core\Config\Entity;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\Core\Test\TestKernel;
use Drupal\Tests\Core\Config\Entity\Fixtures\ConfigEntityBaseWithPluginCollections;
use Drupal\Tests\Core\Plugin\Fixtures\TestConfigurablePlugin;
use Drupal\Tests\UnitTestCase;
use Drupal\TestTools\Random;

/**
 * @coversDefaultClass \Drupal\Core\Config\Entity\ConfigEntityBase
 * @group Config
 */
class ConfigEntityBaseUnitTest extends UnitTestCase {

  /**
   * The entity under test.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityBase|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entity;

  /**
   * The entity type used for testing.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityTypeInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityType;

  /**
   * The entity type manager used for testing.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The ID of the type of the entity under test.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The UUID generator used for testing.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $uuid;

  /**
   * The provider of the entity type.
   *
   * @var string
   */
  protected $provider = 'the_provider_of_the_entity_type';

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $languageManager;

  /**
   * The entity ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The mocked cache backend.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cacheTagsInvalidator;

  /**
   * The mocked typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $typedConfigManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|\Prophecy\Prophecy\ProphecyInterface
   */
  protected $moduleHandler;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface|\Prophecy\Prophecy\ProphecyInterface
   */
  protected $themeHandler;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->id = $this->randomMachineName();
    $values = [
      'id' => $this->id,
      'langcode' => 'en',
      'uuid' => '3bb9ee60-bea5-4622-b89b-a63319d10b3a',
    ];
    $this->entityTypeId = $this->randomMachineName();
    $this->entityType = $this->createMock('\Drupal\Core\Config\Entity\ConfigEntityTypeInterface');
    $this->entityType->expects($this->any())
      ->method('getProvider')
      ->willReturn($this->provider);
    $this->entityType->expects($this->any())
      ->method('getConfigPrefix')
      ->willReturn('test_provider.' . $this->entityTypeId);

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->entityTypeManager->expects($this->any())
      ->method('getDefinition')
      ->with($this->entityTypeId)
      ->willReturn($this->entityType);

    $this->uuid = $this->createMock('\Drupal\Component\Uuid\UuidInterface');

    $this->languageManager = $this->createMock('\Drupal\Core\Language\LanguageManagerInterface');
    $this->languageManager->expects($this->any())
      ->method('getLanguage')
      ->with('en')
      ->willReturn(new Language(['id' => 'en']));

    $this->cacheTagsInvalidator = $this->createMock('Drupal\Core\Cache\CacheTagsInvalidatorInterface');

    $this->typedConfigManager = $this->createMock('Drupal\Core\Config\TypedConfigManagerInterface');

    $this->moduleHandler = $this->prophesize(ModuleHandlerInterface::class);
    $this->themeHandler = $this->prophesize(ThemeHandlerInterface::class);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('uuid', $this->uuid);
    $container->set('language_manager', $this->languageManager);
    $container->set('cache_tags.invalidator', $this->cacheTagsInvalidator);
    $container->set('config.typed', $this->typedConfigManager);
    $container->set('module_handler', $this->moduleHandler->reveal());
    $container->set('theme_handler', $this->themeHandler->reveal());
    \Drupal::setContainer($container);

    $this->entity = $this->getMockForAbstractClass('\Drupal\Core\Config\Entity\ConfigEntityBase', [$values, $this->entityTypeId]);
  }

  /**
   * @covers ::calculateDependencies
   * @covers ::getDependencies
   */
  public function testCalculateDependencies() {
    // Calculating dependencies will reset the dependencies array.
    $this->entity->set('dependencies', ['module' => ['node']]);
    $this->assertEmpty($this->entity->calculateDependencies()->getDependencies());

    // Calculating dependencies will reset the dependencies array using enforced
    // dependencies.
    $this->entity->set('dependencies', ['module' => ['node'], 'enforced' => ['module' => 'views']]);
    $dependencies = $this->entity->calculateDependencies()->getDependencies();
    $this->assertStringContainsString('views', $dependencies['module']);
    $this->assertStringNotContainsString('node', $dependencies['module']);
  }

  /**
   * @covers ::preSave
   */
  public function testPreSaveDuringSync() {
    $this->moduleHandler->moduleExists('node')->willReturn(TRUE);

    $query = $this->createMock('\Drupal\Core\Entity\Query\QueryInterface');
    $storage = $this->createMock('\Drupal\Core\Config\Entity\ConfigEntityStorageInterface');

    $query->expects($this->any())
      ->method('execute')
      ->willReturn([]);
    $query->expects($this->any())
      ->method('condition')
      ->willReturn($query);
    $storage->expects($this->any())
      ->method('getQuery')
      ->willReturn($query);
    $storage->expects($this->any())
      ->method('loadUnchanged')
      ->willReturn($this->entity);

    // Saving an entity will not reset the dependencies array during config
    // synchronization.
    $this->entity->set('dependencies', ['module' => ['node']]);
    $this->entity->preSave($storage);
    $this->assertEmpty($this->entity->getDependencies());

    $this->entity->setSyncing(TRUE);
    $this->entity->set('dependencies', ['module' => ['node']]);
    $this->entity->preSave($storage);
    $dependencies = $this->entity->getDependencies();
    $this->assertContains('node', $dependencies['module']);
  }

  /**
   * @covers ::addDependency
   */
  public function testAddDependency() {
    $method = new \ReflectionMethod('\Drupal\Core\Config\Entity\ConfigEntityBase', 'addDependency');
    $method->invoke($this->entity, 'module', $this->provider);
    $method->invoke($this->entity, 'module', 'core');
    $method->invoke($this->entity, 'module', 'node');
    $dependencies = $this->entity->getDependencies();
    $this->assertNotContains($this->provider, $dependencies['module']);
    $this->assertNotContains('core', $dependencies['module']);
    $this->assertContains('node', $dependencies['module']);

    // Test sorting of dependencies.
    $method->invoke($this->entity, 'module', 'action');
    $dependencies = $this->entity->getDependencies();
    $this->assertEquals(['action', 'node'], $dependencies['module']);

    // Test sorting of dependency types.
    $method->invoke($this->entity, 'entity', 'system.action.id');
    $dependencies = $this->entity->getDependencies();
    $this->assertEquals(['entity', 'module'], array_keys($dependencies));
  }

  /**
   * @covers ::getDependencies
   * @covers ::calculateDependencies
   *
   * @dataProvider providerCalculateDependenciesWithPluginCollections
   */
  public function testCalculateDependenciesWithPluginCollections($definition, $expected_dependencies) {
    $this->moduleHandler->moduleExists('the_provider_of_the_entity_type')->willReturn(TRUE);
    $this->moduleHandler->moduleExists('test')->willReturn(TRUE);
    $this->moduleHandler->moduleExists('test_theme')->willReturn(FALSE);

    $this->themeHandler->themeExists('test_theme')->willReturn(TRUE);

    $values = [];
    $this->entity = $this->getMockBuilder('\Drupal\Tests\Core\Config\Entity\Fixtures\ConfigEntityBaseWithPluginCollections')
      ->setConstructorArgs([$values, $this->entityTypeId])
      ->onlyMethods(['getPluginCollections'])
      ->getMock();

    // Create a configurable plugin that would add a dependency.
    $instance_id = $this->randomMachineName();
    $instance = new TestConfigurablePlugin([], $instance_id, $definition);

    // Create a plugin collection to contain the instance.
    $pluginCollection = $this->getMockBuilder('\Drupal\Core\Plugin\DefaultLazyPluginCollection')
      ->disableOriginalConstructor()
      ->onlyMethods(['get'])
      ->getMock();
    $pluginCollection->expects($this->atLeastOnce())
      ->method('get')
      ->with($instance_id)
      ->willReturn($instance);
    $pluginCollection->addInstanceId($instance_id);

    // Return the mocked plugin collection.
    $this->entity->expects($this->once())
      ->method('getPluginCollections')
      ->willReturn([$pluginCollection]);

    $this->assertEquals($expected_dependencies, $this->entity->calculateDependencies()->getDependencies());
  }

  /**
   * Data provider for testCalculateDependenciesWithPluginCollections.
   *
   * @return array
   */
  public function providerCalculateDependenciesWithPluginCollections() {
    // Start with 'a' so that order of the dependency array is fixed.
    $instance_dependency_1 = 'a' . Random::machineName(10);
    $instance_dependency_2 = 'a' . Random::machineName(11);

    return [
      // Tests that the plugin provider is a module dependency.
      [
        ['provider' => 'test'],
        ['module' => ['test']],
      ],
      // Tests that the plugin provider is a theme dependency.
      [
        ['provider' => 'test_theme'],
        ['theme' => ['test_theme']],
      ],
      // Tests that a plugin that is provided by the same module as the config
      // entity is not added to the dependencies array.
      [
        ['provider' => $this->provider],
        [],
      ],
      // Tests that a config entity that has a plugin which provides config
      // dependencies in its definition has them.
      [
        [
          'provider' => 'test',
          'config_dependencies' => [
            'config' => [$instance_dependency_1],
            'module' => [$instance_dependency_2],
          ],
        ],
        [
          'config' => [$instance_dependency_1],
          'module' => [$instance_dependency_2, 'test'],
        ],
      ],
    ];
  }

  /**
   * @covers ::calculateDependencies
   * @covers ::getDependencies
   * @covers ::onDependencyRemoval
   */
  public function testCalculateDependenciesWithThirdPartySettings() {
    $this->entity = $this->getMockForAbstractClass('\Drupal\Core\Config\Entity\ConfigEntityBase', [[], $this->entityTypeId]);
    $this->entity->setThirdPartySetting('test_provider', 'test', 'test');
    $this->entity->setThirdPartySetting('test_provider2', 'test', 'test');
    $this->entity->setThirdPartySetting($this->provider, 'test', 'test');

    $this->assertEquals(['test_provider', 'test_provider2'], $this->entity->calculateDependencies()->getDependencies()['module']);
    $changed = $this->entity->onDependencyRemoval(['module' => ['test_provider2']]);
    $this->assertTrue($changed, 'Calling onDependencyRemoval with an existing third party dependency provider returns TRUE.');
    $changed = $this->entity->onDependencyRemoval(['module' => ['test_provider3']]);
    $this->assertFalse($changed, 'Calling onDependencyRemoval with a non-existing third party dependency provider returns FALSE.');
    $this->assertEquals(['test_provider'], $this->entity->calculateDependencies()->getDependencies()['module']);
  }

  /**
   * @covers ::__sleep
   */
  public function testSleepWithPluginCollections() {
    $instance_id = 'the_instance_id';
    $instance = new TestConfigurablePlugin([], $instance_id, []);

    $plugin_manager = $this->prophesize(PluginManagerInterface::class);
    $plugin_manager->createInstance($instance_id, ['id' => $instance_id])->willReturn($instance);

    // Also set up a container with the plugin manager so that we can assert
    // that the plugin manager itself is also not serialized.
    $container = TestKernel::setContainerWithKernel();
    $container->set('plugin.manager.foo', $plugin_manager->reveal());

    $entity_values = ['the_plugin_collection_config' => [$instance_id => ['foo' => 'original_value']]];
    $entity = new TestConfigEntityWithPluginCollections($entity_values, $this->entityTypeId);
    $entity->setPluginManager($plugin_manager->reveal());

    // After creating the entity, change the plugin configuration.
    $instance->setConfiguration(['foo' => 'new_value']);

    // After changing the plugin configuration, the entity still has the
    // original value.
    $expected_plugin_config = [$instance_id => ['foo' => 'original_value']];
    $this->assertSame($expected_plugin_config, $entity->get('the_plugin_collection_config'));

    // Ensure the plugin collection and manager is not stored.
    $vars = $entity->__sleep();
    $this->assertNotContains('pluginCollection', $vars);
    $this->assertNotContains('pluginManager', $vars);
    $this->assertSame(['pluginManager' => 'plugin.manager.foo'], $entity->get('_serviceIds'));

    $expected_plugin_config = [$instance_id => ['foo' => 'new_value']];
    // Ensure the updated values are stored in the entity.
    $this->assertSame($expected_plugin_config, $entity->get('the_plugin_collection_config'));
  }

  /**
   * @covers ::setOriginalId
   * @covers ::getOriginalId
   */
  public function testGetOriginalId() {
    $new_id = $this->randomMachineName();
    $this->entity->set('id', $new_id);
    $this->assertSame($this->id, $this->entity->getOriginalId());
    $this->assertSame($this->entity, $this->entity->setOriginalId($new_id));
    $this->assertSame($new_id, $this->entity->getOriginalId());

    // Check that setOriginalId() does not change the entity "isNew" status.
    $this->assertFalse($this->entity->isNew());
    $this->entity->setOriginalId($this->randomMachineName());
    $this->assertFalse($this->entity->isNew());
    $this->entity->enforceIsNew();
    $this->assertTrue($this->entity->isNew());
    $this->entity->setOriginalId($this->randomMachineName());
    $this->assertTrue($this->entity->isNew());
  }

  /**
   * @covers ::isNew
   */
  public function testIsNew() {
    $this->assertFalse($this->entity->isNew());
    $this->assertSame($this->entity, $this->entity->enforceIsNew());
    $this->assertTrue($this->entity->isNew());
    $this->entity->enforceIsNew(FALSE);
    $this->assertFalse($this->entity->isNew());
  }

  /**
   * @covers ::set
   * @covers ::get
   */
  public function testGet() {
    $name = 'id';
    $value = $this->randomMachineName();
    $this->assertSame($this->id, $this->entity->get($name));
    $this->assertSame($this->entity, $this->entity->set($name, $value));
    $this->assertSame($value, $this->entity->get($name));
  }

  /**
   * @covers ::setStatus
   * @covers ::status
   */
  public function testSetStatus() {
    $this->assertTrue($this->entity->status());
    $this->assertSame($this->entity, $this->entity->setStatus(FALSE));
    $this->assertFalse($this->entity->status());
    $this->entity->setStatus(TRUE);
    $this->assertTrue($this->entity->status());
  }

  /**
   * @covers ::enable
   * @depends testSetStatus
   */
  public function testEnable() {
    $this->entity->setStatus(FALSE);
    $this->assertSame($this->entity, $this->entity->enable());
    $this->assertTrue($this->entity->status());
  }

  /**
   * @covers ::disable
   * @depends testSetStatus
   */
  public function testDisable() {
    $this->entity->setStatus(TRUE);
    $this->assertSame($this->entity, $this->entity->disable());
    $this->assertFalse($this->entity->status());
  }

  /**
   * @covers ::setSyncing
   * @covers ::isSyncing
   */
  public function testIsSyncing() {
    $this->assertFalse($this->entity->isSyncing());
    $this->assertSame($this->entity, $this->entity->setSyncing(TRUE));
    $this->assertTrue($this->entity->isSyncing());
    $this->entity->setSyncing(FALSE);
    $this->assertFalse($this->entity->isSyncing());
  }

  /**
   * @covers ::createDuplicate
   */
  public function testCreateDuplicate() {
    $this->entityType->expects($this->exactly(2))
      ->method('getKey')
      ->willReturnMap([
        ['id', 'id'],
        ['uuid', 'uuid'],
      ]);

    $this->entityType->expects($this->once())
      ->method('hasKey')
      ->with('uuid')
      ->willReturn(TRUE);

    $new_uuid = '8607ef21-42bc-4913-978f-8c06207b0395';
    $this->uuid->expects($this->once())
      ->method('generate')
      ->willReturn($new_uuid);

    $duplicate = $this->entity->createDuplicate();
    $this->assertInstanceOf('\Drupal\Core\Entity\EntityBase', $duplicate);
    $this->assertNotSame($this->entity, $duplicate);
    $this->assertFalse($this->entity->isNew());
    $this->assertTrue($duplicate->isNew());
    $this->assertNull($duplicate->id());
    $this->assertNull($duplicate->getOriginalId());
    $this->assertNotEquals($this->entity->uuid(), $duplicate->uuid());
    $this->assertSame($new_uuid, $duplicate->uuid());
  }

  /**
   * @covers ::sort
   */
  public function testSort() {
    $this->entityTypeManager->expects($this->any())
      ->method('getDefinition')
      ->with($this->entityTypeId)
      ->willReturn([
        'entity_keys' => [
          'label' => 'label',
        ],
      ]);

    $entity_a = $this->createMock(ConfigEntityBase::class);
    $entity_a->expects($this->atLeastOnce())
      ->method('label')
      ->willReturn('foo');
    $entity_b = $this->createMock(ConfigEntityBase::class);
    $entity_b->expects($this->atLeastOnce())
      ->method('label')
      ->willReturn('bar');

    // Test sorting by label.
    $list = [$entity_a, $entity_b];
    // Suppress errors because of https://bugs.php.net/bug.php?id=50688.
    @usort($list, '\Drupal\Core\Config\Entity\ConfigEntityBase::sort');
    $this->assertSame($entity_b, $list[0]);

    $list = [$entity_b, $entity_a];
    // Suppress errors because of https://bugs.php.net/bug.php?id=50688.
    @usort($list, '\Drupal\Core\Config\Entity\ConfigEntityBase::sort');
    $this->assertSame($entity_b, $list[0]);

    // Test sorting by weight.
    $entity_a->weight = 0;
    $entity_b->weight = 1;
    $list = [$entity_b, $entity_a];
    // Suppress errors because of https://bugs.php.net/bug.php?id=50688.
    @usort($list, '\Drupal\Core\Config\Entity\ConfigEntityBase::sort');
    $this->assertSame($entity_a, $list[0]);

    $list = [$entity_a, $entity_b];
    // Suppress errors because of https://bugs.php.net/bug.php?id=50688.
    @usort($list, '\Drupal\Core\Config\Entity\ConfigEntityBase::sort');
    $this->assertSame($entity_a, $list[0]);
  }

  /**
   * @covers ::toArray
   */
  public function testToArray() {
    $this->typedConfigManager->expects($this->never())
      ->method('getDefinition');
    $this->entityType->expects($this->any())
      ->method('getPropertiesToExport')
      ->willReturn(['id' => 'configId', 'dependencies' => 'dependencies']);
    $properties = $this->entity->toArray();
    $this->assertIsArray($properties);
    $this->assertEquals(['configId' => $this->entity->id(), 'dependencies' => []], $properties);
  }

  /**
   * @covers ::toArray
   */
  public function testToArrayIdKey() {
    $entity = $this->getMockForAbstractClass('\Drupal\Core\Config\Entity\ConfigEntityBase', [[], $this->entityTypeId], '', TRUE, TRUE, TRUE, ['id', 'get']);
    $entity->expects($this->atLeastOnce())
      ->method('id')
      ->willReturn($this->id);
    $entity->expects($this->once())
      ->method('get')
      ->with('dependencies')
      ->willReturn([]);
    $this->typedConfigManager->expects($this->never())
      ->method('getDefinition');
    $this->entityType->expects($this->any())
      ->method('getPropertiesToExport')
      ->willReturn(['id' => 'configId', 'dependencies' => 'dependencies']);
    $this->entityType->expects($this->once())
      ->method('getKey')
      ->with('id')
      ->willReturn('id');
    $properties = $entity->toArray();
    $this->assertIsArray($properties);
    $this->assertEquals(['configId' => $entity->id(), 'dependencies' => []], $properties);
  }

  /**
   * @covers ::getThirdPartySetting
   * @covers ::setThirdPartySetting
   * @covers ::getThirdPartySettings
   * @covers ::unsetThirdPartySetting
   * @covers ::getThirdPartyProviders
   */
  public function testThirdPartySettings() {
    $key = 'test';
    $third_party = 'test_provider';
    $value = $this->getRandomGenerator()->string();

    // Test getThirdPartySetting() with no settings.
    $this->assertEquals($value, $this->entity->getThirdPartySetting($third_party, $key, $value));
    $this->assertNull($this->entity->getThirdPartySetting($third_party, $key));

    // Test setThirdPartySetting().
    $this->entity->setThirdPartySetting($third_party, $key, $value);
    $this->assertEquals($value, $this->entity->getThirdPartySetting($third_party, $key));
    $this->assertEquals($value, $this->entity->getThirdPartySetting($third_party, $key, $this->getRandomGenerator()->string()));

    // Test getThirdPartySettings().
    $this->entity->setThirdPartySetting($third_party, 'test2', 'value2');
    $this->assertEquals([$key => $value, 'test2' => 'value2'], $this->entity->getThirdPartySettings($third_party));

    // Test getThirdPartyProviders().
    $this->entity->setThirdPartySetting('test_provider2', $key, $value);
    $this->assertEquals([$third_party, 'test_provider2'], $this->entity->getThirdPartyProviders());

    // Test unsetThirdPartyProviders().
    $this->entity->unsetThirdPartySetting('test_provider2', $key);
    $this->assertEquals([$third_party], $this->entity->getThirdPartyProviders());
  }

  /**
   * @covers ::toArray
   */
  public function testToArraySchemaException() {
    $this->entityType->expects($this->any())
      ->method('getPropertiesToExport')
      ->willReturn(NULL);
    $this->entityType->expects($this->any())
      ->method('getClass')
      ->willReturn("FooConfigEntity");
    $this->expectException(SchemaIncompleteException::class);
    $this->expectExceptionMessage("Entity type 'FooConfigEntity' is missing 'config_export' definition in its annotation");
    $this->entity->toArray();
  }

}

class TestConfigEntityWithPluginCollections extends ConfigEntityBaseWithPluginCollections {

  protected $pluginCollection;

  protected $pluginManager;

  protected $the_plugin_collection_config;

  public function setPluginManager(PluginManagerInterface $plugin_manager) {
    $this->pluginManager = $plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    if (!$this->pluginCollection) {
      $this->pluginCollection = new DefaultLazyPluginCollection($this->pluginManager, ['the_instance_id' => ['id' => 'the_instance_id']]);
    }
    return ['the_plugin_collection_config' => $this->pluginCollection];
  }

}
