<?php

namespace Drupal\Tests\field\Functional\EntityReference;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\config\Traits\AssertConfigEntityImportTrait;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;

/**
 * Tests various Entity reference UI components.
 *
 * @group entity_reference
 */
class EntityReferenceIntegrationTest extends BrowserTestBase {

  use AssertConfigEntityImportTrait;
  use EntityReferenceTestTrait;

  /**
   * The entity type used in this test.
   *
   * @var string
   */
  protected $entityType = 'entity_test';

  /**
   * The bundle used in this test.
   *
   * @var string
   */
  protected $bundle = 'entity_test';

  /**
   * The name of the field used in this test.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * Modules to install.
   *
   * @var array
   */
  protected static $modules = ['config_test', 'entity_test', 'field_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a test user.
    $web_user = $this->drupalCreateUser([
      'administer entity_test content',
      'administer entity_test fields',
      'view test entity',
    ]);
    $this->drupalLogin($web_user);
  }

  /**
   * Tests the entity reference field with all its supported field widgets.
   */
  public function testSupportedEntityTypesAndWidgets() {
    foreach ($this->getTestEntities() as $key => $referenced_entities) {
      $this->fieldName = 'field_test_' . $referenced_entities[0]->getEntityTypeId();

      // Create an Entity reference field.
      $this->createEntityReferenceField($this->entityType, $this->bundle, $this->fieldName, $this->fieldName, $referenced_entities[0]->getEntityTypeId(), 'default', [], 2);

      /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
      $display_repository = \Drupal::service('entity_display.repository');

      // Test the default 'entity_reference_autocomplete' widget.
      $display_repository->getFormDisplay($this->entityType, $this->bundle)
        ->setComponent($this->fieldName)
        ->save();

      $entity_name = $this->randomMachineName();
      $edit = [
        'name[0][value]' => $entity_name,
        $this->fieldName . '[0][target_id]' => $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')',
        // Test an input of the entity label without an ' (entity_id)' suffix.
        $this->fieldName . '[1][target_id]' => $referenced_entities[1]->label(),
      ];
      $this->drupalGet($this->entityType . '/add');
      $this->submitForm($edit, 'Save');
      $this->assertFieldValues($entity_name, $referenced_entities);

      // Try to post the form again with no modification and check if the field
      // values remain the same.
      /** @var \Drupal\Core\Entity\EntityStorageInterface $storage */
      $storage = $this->container->get('entity_type.manager')->getStorage($this->entityType);
      $entity = current($storage->loadByProperties(['name' => $entity_name]));
      $this->drupalGet($this->entityType . '/manage/' . $entity->id() . '/edit');
      $this->assertSession()->fieldValueEquals($this->fieldName . '[0][target_id]', $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')');
      $this->assertSession()->fieldValueEquals($this->fieldName . '[1][target_id]', $referenced_entities[1]->label() . ' (' . $referenced_entities[1]->id() . ')');

      $this->submitForm([], 'Save');
      $this->assertFieldValues($entity_name, $referenced_entities);

      // Test the 'entity_reference_autocomplete_tags' widget.
      $display_repository->getFormDisplay($this->entityType, $this->bundle)
        ->setComponent($this->fieldName, [
          'type' => 'entity_reference_autocomplete_tags',
        ])->save();

      $entity_name = $this->randomMachineName();
      $target_id = $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')';
      // Test an input of the entity label without an ' (entity_id)' suffix.
      $target_id .= ', ' . $referenced_entities[1]->label();
      $edit = [
        'name[0][value]' => $entity_name,
        $this->fieldName . '[target_id]' => $target_id,
      ];
      $this->drupalGet($this->entityType . '/add');
      $this->submitForm($edit, 'Save');
      $this->assertFieldValues($entity_name, $referenced_entities);

      // Try to post the form again with no modification and check if the field
      // values remain the same.
      $entity = current($storage->loadByProperties(['name' => $entity_name]));
      $this->drupalGet($this->entityType . '/manage/' . $entity->id() . '/edit');
      $this->assertSession()->fieldValueEquals($this->fieldName . '[target_id]', $target_id . ' (' . $referenced_entities[1]->id() . ')');

      $this->submitForm([], 'Save');
      $this->assertFieldValues($entity_name, $referenced_entities);

      // Test all the other widgets supported by the entity reference field.
      // Since we don't know the form structure for these widgets, just test
      // that editing and saving an already created entity works.
      $exclude = ['entity_reference_autocomplete', 'entity_reference_autocomplete_tags'];
      $entity = current($storage->loadByProperties(['name' => $entity_name]));
      $supported_widgets = \Drupal::service('plugin.manager.field.widget')->getOptions('entity_reference');
      $supported_widget_types = array_diff(array_keys($supported_widgets), $exclude);

      foreach ($supported_widget_types as $widget_type) {
        $display_repository->getFormDisplay($this->entityType, $this->bundle)
          ->setComponent($this->fieldName, [
            'type' => $widget_type,
          ])->save();

        $this->drupalGet($this->entityType . '/manage/' . $entity->id() . '/edit');
        $this->submitForm([], 'Save');
        $this->assertFieldValues($entity_name, $referenced_entities);
      }

      // Reset to the default 'entity_reference_autocomplete' widget.
      $display_repository->getFormDisplay($this->entityType, $this->bundle)
        ->setComponent($this->fieldName)
        ->save();

      // Set first entity as the default_value.
      $field_edit = [
        'set_default_value' => '1',
        'default_value_input[' . $this->fieldName . '][0][target_id]' => $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')',
      ];
      if ($key == 'content') {
        $field_edit['settings[handler_settings][target_bundles][' . $referenced_entities[0]->getEntityTypeId() . ']'] = TRUE;
      }
      $this->drupalGet($this->entityType . '/structure/' . $this->bundle . '/fields/' . $this->entityType . '.' . $this->bundle . '.' . $this->fieldName);
      $this->submitForm($field_edit, 'Save settings');
      // Ensure the configuration has the expected dependency on the entity that
      // is being used a default value.
      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
      $this->assertContains($referenced_entities[0]->getConfigDependencyName(), $field->getDependencies()[$key], new FormattableMarkup('Expected @type dependency @name found', ['@type' => $key, '@name' => $referenced_entities[0]->getConfigDependencyName()]));
      // Ensure that the field can be imported without change even after the
      // default value deleted.
      $referenced_entities[0]->delete();
      // Reload the field since deleting the default value can change the field.
      \Drupal::entityTypeManager()->getStorage($field->getEntityTypeId())->resetCache([$field->id()]);
      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
      $this->assertConfigEntityImport($field);

      // Once the default value has been removed after saving the dependency
      // should be removed.
      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
      $field->save();
      $dependencies = $field->getDependencies();
      $this->assertFalse(isset($dependencies[$key]) && in_array($referenced_entities[0]->getConfigDependencyName(), $dependencies[$key]), new FormattableMarkup('@type dependency @name does not exist.', ['@type' => $key, '@name' => $referenced_entities[0]->getConfigDependencyName()]));
    }
  }

  /**
   * Asserts that the reference field values are correct.
   *
   * @param string $entity_name
   *   The name of the test entity.
   * @param \Drupal\Core\Entity\EntityInterface[] $referenced_entities
   *   An array of referenced entities.
   *
   * @internal
   */
  protected function assertFieldValues(string $entity_name, array $referenced_entities): void {
    $entity = current($this->container->get('entity_type.manager')->getStorage(
    $this->entityType)->loadByProperties(['name' => $entity_name]));

    $this->assertNotEmpty($entity, new FormattableMarkup('%entity_type: Entity found in the database.', ['%entity_type' => $this->entityType]));

    $this->assertEquals($referenced_entities[0]->id(), $entity->{$this->fieldName}->target_id);
    $this->assertEquals($referenced_entities[0]->id(), $entity->{$this->fieldName}->entity->id());
    $this->assertEquals($referenced_entities[0]->label(), $entity->{$this->fieldName}->entity->label());

    $this->assertEquals($referenced_entities[1]->id(), $entity->{$this->fieldName}[1]->target_id);
    $this->assertEquals($referenced_entities[1]->id(), $entity->{$this->fieldName}[1]->entity->id());
    $this->assertEquals($referenced_entities[1]->label(), $entity->{$this->fieldName}[1]->entity->label());
  }

  /**
   * Creates two content and two config test entities.
   *
   * @return array
   *   An array of entity objects.
   */
  protected function getTestEntities() {
    $storage = \Drupal::entityTypeManager()->getStorage('config_test');
    $config_entity_1 = $storage->create(['id' => $this->randomMachineName(), 'label' => $this->randomMachineName()]);
    $config_entity_1->save();
    $config_entity_2 = $storage->create(['id' => $this->randomMachineName(), 'label' => $this->randomMachineName()]);
    $config_entity_2->save();

    $content_entity_1 = EntityTest::create(['name' => $this->randomMachineName()]);
    $content_entity_1->save();
    $content_entity_2 = EntityTest::create(['name' => $this->randomMachineName()]);
    $content_entity_2->save();

    return [
      'config' => [
        $config_entity_1,
        $config_entity_2,
      ],
      'content' => [
        $content_entity_1,
        $content_entity_2,
      ],
    ];
  }

}
