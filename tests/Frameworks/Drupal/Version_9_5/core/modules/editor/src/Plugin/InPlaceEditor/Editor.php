<?php

namespace Drupal\editor\Plugin\InPlaceEditor;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\filter\Entity\FilterFormat;
use Drupal\quickedit\Plugin\InPlaceEditorInterface;
use Drupal\filter\Plugin\FilterInterface;

/**
 * Defines the formatted text in-place editor.
 *
 * @InPlaceEditor(
 *   id = "editor"
 * )
 *
 * @deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. There is no
 * replacement.
 *
 * @see https://www.drupal.org/node/3271653
 */
class Editor extends PluginBase implements InPlaceEditorInterface {

  /**
   * Constructs an Editor object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    @trigger_error('Drupal\editor\InPlaceEditor\Editor is deprecated in drupal:9.5.0 and is removed from drupal:10.0.0. There is no replacement. See https://www.drupal.org/node/3271653', E_USER_DEPRECATED);
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function isCompatible(FieldItemListInterface $items) {
    $field_definition = $items->getFieldDefinition();

    // This editor is incompatible with multivalued fields.
    if ($field_definition->getFieldStorageDefinition()->getCardinality() != 1) {
      return FALSE;
    }
    // This editor is compatible with formatted ("rich") text fields; but only
    // if there is a currently active text format, that text format has an
    // associated editor and that editor supports inline editing.
    elseif ($editor = editor_load($items[0]->format)) {
      $definition = \Drupal::service('plugin.manager.editor')->getDefinition($editor->getEditor());
      if ($definition['supports_inline_editing'] === TRUE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(FieldItemListInterface $items) {
    $format_id = $items[0]->format;
    $metadata['format'] = $format_id;
    $metadata['formatHasTransformations'] = $this->textFormatHasTransformationFilters($format_id);
    return $metadata;
  }

  /**
   * Returns whether the text format has transformation filters.
   *
   * @param int $format_id
   *   A text format ID.
   *
   * @return bool
   */
  protected function textFormatHasTransformationFilters($format_id) {
    $format = FilterFormat::load($format_id);
    return (bool) count(array_intersect([FilterInterface::TYPE_TRANSFORM_REVERSIBLE, FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE], $format->getFiltertypes()));
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachments() {
    $user = \Drupal::currentUser();

    $user_format_ids = array_keys(filter_formats($user));
    $manager = \Drupal::service('plugin.manager.editor');
    $definitions = $manager->getDefinitions();

    // Filter the current user's formats to those that support inline editing.
    $formats = [];
    foreach ($user_format_ids as $format_id) {
      if ($editor = editor_load($format_id)) {
        $editor_id = $editor->getEditor();
        if (isset($definitions[$editor_id]['supports_inline_editing']) && $definitions[$editor_id]['supports_inline_editing'] === TRUE) {
          $formats[] = $format_id;
        }
      }
    }

    // Get the attachments for all text editors that the user might use.
    $attachments = $manager->getAttachments($formats);

    // Also include editor.module's formatted text editor.
    $attachments['library'][] = 'quickedit/quickedit.inPlaceEditor.formattedText';

    return $attachments;
  }

}
