<?php

namespace Drupal\entity_test\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'serialized_property_item_test' entity field type.
 *
 * @FieldType(
 *   id = "serialized_property_item_test",
 *   label = @Translation("Test serialized property field item"),
 *   description = @Translation("A field containing a string representing serialized data."),
 *   category = @Translation("Field"),
 *   serialized_property_names = {
 *     "value"
 *   }
 * )
 */
class SerializedPropertyItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Test serialized value'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'text',
          'size' => 'big',
        ],
      ],
    ];
  }

}
