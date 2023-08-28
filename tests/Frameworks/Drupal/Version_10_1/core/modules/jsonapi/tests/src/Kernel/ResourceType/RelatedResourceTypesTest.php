<?php

namespace Drupal\Tests\jsonapi\Kernel\ResourceType;

use Drupal\Tests\jsonapi\Kernel\JsonapiKernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Error\Warning;

/**
 * @coversDefaultClass \Drupal\jsonapi\ResourceType\ResourceType
 * @coversClass \Drupal\jsonapi\ResourceType\ResourceTypeRepository
 * @group jsonapi
 *
 * @internal
 */
class RelatedResourceTypesTest extends JsonapiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'jsonapi',
    'serialization',
    'system',
    'user',
    'field',
  ];

  /**
   * The JSON:API resource type repository under test.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepository
   */
  protected $resourceTypeRepository;

  /**
   * The JSON:API resource type for `node--foo`.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceType
   */
  protected $fooType;

  /**
   * The JSON:API resource type for `node--bar`.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceType
   */
  protected $barType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Add the entity schemas.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');

    // Add the additional table schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);

    NodeType::create([
      'type' => 'foo',
    ])->save();

    NodeType::create([
      'type' => 'bar',
    ])->save();

    $this->createEntityReferenceField(
      'node',
      'foo',
      'field_ref_bar',
      'Bar Reference',
      'node',
      'default',
      ['target_bundles' => ['bar']]
    );

    $this->createEntityReferenceField(
      'node',
      'foo',
      'field_ref_foo',
      'Foo Reference',
      'node',
      'default',
      // Important to test self-referencing resource types.
      ['target_bundles' => ['foo']]
    );

    $this->createEntityReferenceField(
      'node',
      'foo',
      'field_ref_any',
      'Any Bundle Reference',
      'node',
      'default',
      // This should result in a reference to any bundle.
      ['target_bundles' => NULL]
    );

    $this->resourceTypeRepository = $this->container->get('jsonapi.resource_type.repository');
  }

  /**
   * @covers ::getRelatableResourceTypes
   * @dataProvider getRelatableResourceTypesProvider
   */
  public function testGetRelatableResourceTypes($resource_type_name, $relatable_type_names) {
    // We're only testing the fields that we set up.
    $test_fields = [
      'field_ref_foo',
      'field_ref_bar',
      'field_ref_any',
    ];

    $resource_type = $this->resourceTypeRepository->getByTypeName($resource_type_name);

    // This extracts just the relationship fields under test.
    $subjects = array_intersect_key(
      $resource_type->getRelatableResourceTypes(),
      array_flip($test_fields)
    );

    // Map the related resource type to their type name so we can just compare
    // the type names rather that the whole object.
    foreach ($test_fields as $field_name) {
      if (isset($subjects[$field_name])) {
        $subjects[$field_name] = array_map(function ($resource_type) {
          return $resource_type->getTypeName();
        }, $subjects[$field_name]);
      }
    }

    $this->assertEquals($relatable_type_names, $subjects);
  }

  /**
   * @covers ::getRelatableResourceTypes
   * @dataProvider getRelatableResourceTypesProvider
   */
  public function getRelatableResourceTypesProvider() {
    return [
      [
        'node--foo',
        [
          'field_ref_foo' => ['node--foo'],
          'field_ref_bar' => ['node--bar'],
          'field_ref_any' => ['node--foo', 'node--bar'],
        ],
      ],
      ['node--bar', []],
    ];
  }

  /**
   * @covers ::getRelatableResourceTypesByField
   * @dataProvider getRelatableResourceTypesByFieldProvider
   */
  public function testGetRelatableResourceTypesByField($entity_type_id, $bundle, $field) {
    $resource_type = $this->resourceTypeRepository->get($entity_type_id, $bundle);
    $relatable_types = $resource_type->getRelatableResourceTypes();
    $this->assertSame(
      $relatable_types[$field],
      $resource_type->getRelatableResourceTypesByField($field)
    );
  }

  /**
   * Provides cases to test getRelatableTypesByField.
   */
  public function getRelatableResourceTypesByFieldProvider() {
    return [
      ['node', 'foo', 'field_ref_foo'],
      ['node', 'foo', 'field_ref_bar'],
      ['node', 'foo', 'field_ref_any'],
    ];
  }

  /**
   * Ensure a graceful failure when a field can references a missing bundle.
   *
   * @covers \Drupal\jsonapi\ResourceType\ResourceTypeRepository::all
   * @covers \Drupal\jsonapi\ResourceType\ResourceTypeRepository::calculateRelatableResourceTypes
   * @covers \Drupal\jsonapi\ResourceType\ResourceTypeRepository::getRelatableResourceTypesFromFieldDefinition
   *
   * @link https://www.drupal.org/project/drupal/issues/2996114
   */
  public function testGetRelatableResourceTypesFromFieldDefinition() {
    $field_config_storage = $this->container->get('entity_type.manager')->getStorage('field_config');

    static::assertCount(0, $this->resourceTypeRepository->get('node', 'foo')->getRelatableResourceTypesByField('field_relationship'));
    $this->createEntityReferenceField('node', 'foo', 'field_ref_with_missing_bundle', 'Related entity', 'node', 'default', [
      'target_bundles' => ['missing_bundle'],
    ]);
    $fields = $field_config_storage->loadByProperties(['field_name' => 'field_ref_with_missing_bundle']);
    static::assertSame(['missing_bundle'], $fields['node.foo.field_ref_with_missing_bundle']->getItemDefinition()->getSetting('handler_settings')['target_bundles']);

    try {
      $this->resourceTypeRepository->get('node', 'foo')->getRelatableResourceTypesByField('field_ref_with_missing_bundle');
      static::fail('The above code must produce a warning since the "missing_bundle" does not exist.');
    }
    catch (Warning $e) {
      static::assertSame(
        'The "field_ref_with_missing_bundle" at "node:foo" references the "node:missing_bundle" entity type that does not exist. Please take action.',
        $e->getMessage()
      );
    }
  }

}
