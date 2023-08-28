<?php

namespace Drupal\Tests\layout_builder\Functional;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\image\Kernel\ImageFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;

/**
 * Tests rendering default field values in Layout Builder.
 *
 * @group layout_builder
 */
class LayoutBuilderDefaultValuesTest extends BrowserTestBase {

  use ImageFieldCreationTrait;
  use TestFileCreationTrait {
    getTestFiles as drupalGetTestFiles;
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'layout_builder',
    'block',
    'node',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createContentType([
      'type' => 'test_node_type',
      'name' => 'Test Node Type',
    ]);

    $this->addTextFields();
    $this->addImageFields();

    // Create node 1 with specific values.
    $this->createNode([
      'type' => 'test_node_type',
      'title' => 'Test Node 1 Has Values',
      'field_string_no_default' => 'No default, no problem.',
      'field_string_with_default' => 'It is ok to be different',
      'field_string_with_callback' => 'Not from a callback',
      'field_string_late_default' => 'I am way ahead of you.',
      'field_image_with_default' => [
        'target_id' => 2,
        'alt' => 'My different alt text',
      ],
      'field_image_no_default' => [
        'target_id' => 3,
        'alt' => 'My third alt text',
      ],
    ]);

    // Create node 2 relying on defaults.
    $this->createNode([
      'type' => 'test_node_type',
      'title' => 'Test Node 2 Uses Defaults',
    ]);

    // Add default value to field_string_late_default.
    $field = FieldConfig::loadByName('node', 'test_node_type', 'field_string_late_default');
    $field->setDefaultValue('Too late!');
    $field->save();
  }

  /**
   * Tests display of default field values.
   */
  public function testDefaultValues() {
    // Begin by viewing nodes with Layout Builder disabled.
    $this->assertNodeWithValues();
    $this->assertNodeWithDefaultValues();

    // Enable layout builder.
    LayoutBuilderEntityViewDisplay::load('node.test_node_type.default')
      ->enableLayoutBuilder()
      ->save();

    // Confirm that default values are handled consistently by Layout Builder.
    $this->assertNodeWithValues();
    $this->assertNodeWithDefaultValues();
  }

  /**
   * Test for expected text on node 1.
   */
  protected function assertNodeWithValues() {
    $this->drupalGet('node/1');
    $assert_session = $this->assertSession();
    // String field with no default should render a value.
    $assert_session->pageTextContains('field_string_no_default');
    $assert_session->pageTextContains('No default, no problem.');
    // String field with default should render non-default value.
    $assert_session->pageTextContains('field_string_with_default');
    $assert_session->pageTextNotContains('This is my default value');
    $assert_session->pageTextContains('It is ok to be different');
    // String field with callback should render non-default value.
    $assert_session->pageTextContains('field_string_with_callback');
    $assert_session->pageTextNotContains('This is from my default value callback');
    $assert_session->pageTextContains('Not from a callback');
    // String field with "late" default should render non-default value.
    $assert_session->pageTextContains('field_string_late_default');
    $assert_session->pageTextNotContains('Too late!');
    $assert_session->pageTextContains('I am way ahead of you');
    // Image field with default should render non-default value.
    $assert_session->pageTextContains('field_image_with_default');
    $assert_session->responseNotContains('My default alt text');
    $assert_session->responseNotContains('test-file-1');
    $assert_session->responseContains('My different alt text');
    $assert_session->responseContains('test-file-2');
    // Image field with no default should render a value.
    $assert_session->pageTextContains('field_image_no_default');
    $assert_session->responseContains('My third alt text');
    $assert_session->responseContains('test-file-3');
  }

  /**
   * Test for expected text on node 2.
   */
  protected function assertNodeWithDefaultValues() {
    $this->drupalGet('node/2');
    $assert_session = $this->assertSession();
    // String field with no default should not render.
    $assert_session->pageTextNotContains('field_string_no_default');
    // String with default value should render with default value.
    $assert_session->pageTextContains('field_string');
    $assert_session->pageTextContains('This is my default value');
    // String field with callback should render value from callback.
    $assert_session->pageTextContains('field_string_with_callback');
    $assert_session->pageTextContains('This is from my default value callback');
    // String field with "late" default should not render.
    $assert_session->pageTextNotContains('field_string_late_default');
    $assert_session->pageTextNotContains('Too late!');
    // Image field with default should render default value.
    $assert_session->pageTextContains('field_image_with_default');
    $assert_session->responseContains('My default alt text');
    $assert_session->responseContains('test-file-1');
    // Image field with no default should not render.
    $assert_session->pageTextNotContains('field_image_no_default');
  }

  /**
   * Helper function to add string fields.
   */
  protected function addTextFields() {
    // String field with no default.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_string_no_default',
      'entity_type' => 'node',
      'type' => 'string',
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'test_node_type',
    ]);
    $field->save();

    // String field with default value.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_string_with_default',
      'entity_type' => 'node',
      'type' => 'string',
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'test_node_type',
    ]);
    $field->setDefaultValue('This is my default value');
    $field->save();

    // String field with default callback.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_string_with_callback',
      'entity_type' => 'node',
      'type' => 'string',
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'test_node_type',
    ]);
    $field->setDefaultValueCallback('Drupal\Tests\layout_builder\Functional\LayoutBuilderDefaultValuesTest::defaultValueCallback');
    $field->save();

    // String field with late default. We will set default later.
    $field_storage = FieldStorageConfig::create([
      'field_name' => 'field_string_late_default',
      'entity_type' => 'node',
      'type' => 'string',
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'test_node_type',
    ]);
    $field->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $display_repository->getViewDisplay('node', 'test_node_type')
      ->setComponent('field_string_no_default', [
        'type' => 'string',
      ])
      ->setComponent('field_string_with_default', [
        'type' => 'string',
      ])
      ->setComponent('field_string_with_callback', [
        'type' => 'string',
      ])
      ->setComponent('field_string_late_default', [
        'type' => 'string',
      ])
      ->save();
  }

  /**
   * Helper function to add image fields.
   */
  protected function addImageFields() {
    // Create files to use as the default images.
    $files = $this->drupalGetTestFiles('image');
    $images = [];
    for ($i = 1; $i <= 3; $i++) {
      $filename = "test-file-$i";
      $desired_filepath = 'public://' . $filename;
      \Drupal::service('file_system')->copy($files[0]->uri, $desired_filepath, FileSystemInterface::EXISTS_ERROR);
      $file = File::create([
        'uri' => $desired_filepath,
        'filename' => $filename,
        'name' => $filename,
      ]);
      $file->save();
      $images[] = $file;
    }

    $field_name = 'field_image_with_default';
    $storage_settings['default_image'] = [
      'uuid' => $images[0]->uuid(),
      'alt' => 'My default alt text',
      'title' => '',
      'width' => 0,
      'height' => 0,
    ];
    $field_settings['default_image'] = [
      'uuid' => $images[0]->uuid(),
      'alt' => 'My default alt text',
      'title' => '',
      'width' => 0,
      'height' => 0,
    ];
    $widget_settings = [
      'preview_image_style' => 'medium',
    ];
    $this->createImageField($field_name, 'test_node_type', $storage_settings, $field_settings, $widget_settings);

    $field_name = 'field_image_no_default';
    $storage_settings = [];
    $field_settings = [];
    $widget_settings = [
      'preview_image_style' => 'medium',
    ];
    $this->createImageField($field_name, 'test_node_type', $storage_settings, $field_settings, $widget_settings);
  }

  /**
   * Sample 'default value' callback.
   */
  public static function defaultValueCallback(FieldableEntityInterface $entity, FieldDefinitionInterface $definition) {
    return [['value' => 'This is from my default value callback']];
  }

}
