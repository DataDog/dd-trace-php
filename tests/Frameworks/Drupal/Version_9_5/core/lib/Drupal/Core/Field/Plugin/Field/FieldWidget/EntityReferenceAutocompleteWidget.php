<?php

namespace Drupal\Core\Field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the 'entity_reference_autocomplete' widget.
 *
 * @FieldWidget(
 *   id = "entity_reference_autocomplete",
 *   label = @Translation("Autocomplete"),
 *   description = @Translation("An autocomplete text field."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class EntityReferenceAutocompleteWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'match_operator' => 'CONTAINS',
      'match_limit' => 10,
      'size' => 60,
      'placeholder' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['match_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Autocomplete matching'),
      '#default_value' => $this->getSetting('match_operator'),
      '#options' => $this->getMatchOperatorOptions(),
      '#description' => $this->t('Select the method used to collect autocomplete suggestions. Note that <em>Contains</em> can cause performance issues on sites with thousands of entities.'),
    ];
    $element['match_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of results'),
      '#default_value' => $this->getSetting('match_limit'),
      '#min' => 0,
      '#description' => $this->t('The number of suggestions that will be listed. Use <em>0</em> to remove the limit.'),
    ];
    $element['size'] = [
      '#type' => 'number',
      '#title' => $this->t('Size of textfield'),
      '#default_value' => $this->getSetting('size'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $element['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $this->getSetting('placeholder'),
      '#description' => $this->t('Text that will be shown inside the field until a value is entered. This hint is usually a sample value or a brief description of the expected format.'),
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $operators = $this->getMatchOperatorOptions();
    $summary[] = $this->t('Autocomplete matching: @match_operator', ['@match_operator' => $operators[$this->getSetting('match_operator')]]);
    $size = $this->getSetting('match_limit') ?: $this->t('unlimited');
    $summary[] = $this->t('Autocomplete suggestion list size: @size', ['@size' => $size]);
    $summary[] = $this->t('Textfield size: @size', ['@size' => $this->getSetting('size')]);
    $placeholder = $this->getSetting('placeholder');
    if (!empty($placeholder)) {
      $summary[] = $this->t('Placeholder: @placeholder', ['@placeholder' => $placeholder]);
    }
    else {
      $summary[] = $this->t('No placeholder');
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $entity = $items->getEntity();
    $referenced_entities = $items->referencedEntities();

    // Append the match operation to the selection settings.
    $selection_settings = $this->getFieldSetting('handler_settings') + [
      'match_operator' => $this->getSetting('match_operator'),
      'match_limit' => $this->getSetting('match_limit'),
    ];

    $element += [
      '#type' => 'entity_autocomplete',
      '#target_type' => $this->getFieldSetting('target_type'),
      '#selection_handler' => $this->getFieldSetting('handler'),
      '#selection_settings' => $selection_settings,
      // Entity reference field items are handling validation themselves via
      // the 'ValidReference' constraint.
      '#validate_reference' => FALSE,
      '#maxlength' => 1024,
      '#default_value' => $referenced_entities[$delta] ?? NULL,
      '#size' => $this->getSetting('size'),
      '#placeholder' => $this->getSetting('placeholder'),
    ];

    if ($bundle = $this->getAutocreateBundle()) {
      $element['#autocreate'] = [
        'bundle' => $bundle,
        'uid' => ($entity instanceof EntityOwnerInterface) ? $entity->getOwnerId() : \Drupal::currentUser()->id(),
      ];
    }

    return ['target_id' => $element];
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $error, array $form, FormStateInterface $form_state) {
    return $element['target_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as $key => $value) {
      // The entity_autocomplete form element returns an array when an entity
      // was "autocreated", so we need to move it up a level.
      if (is_array($value['target_id'])) {
        unset($values[$key]['target_id']);
        $values[$key] += $value['target_id'];
      }
    }

    return $values;
  }

  /**
   * Returns the name of the bundle which will be used for autocreated entities.
   *
   * @return string
   *   The bundle name. If autocreate is not active, NULL will be returned.
   */
  protected function getAutocreateBundle() {
    $bundle = NULL;
    if ($this->getSelectionHandlerSetting('auto_create')) {
      $target_bundles = $this->getSelectionHandlerSetting('target_bundles');
      // If there's no target bundle at all, use the target_type. It's the
      // default for bundleless entity types.
      if (empty($target_bundles)) {
        $bundle = $this->getFieldSetting('target_type');
      }
      // If there's only one target bundle, use it.
      elseif (count($target_bundles) == 1) {
        $bundle = reset($target_bundles);
      }
      // If there's more than one target bundle, use the autocreate bundle
      // stored in selection handler settings.
      elseif (!$bundle = $this->getSelectionHandlerSetting('auto_create_bundle')) {
        // If no bundle has been set as auto create target means that there is
        // an inconsistency in entity reference field settings.
        trigger_error(sprintf(
          "The 'Create referenced entities if they don't already exist' option is enabled but a specific destination bundle is not set. You should re-visit and fix the settings of the '%s' (%s) field.",
          $this->fieldDefinition->getLabel(),
          $this->fieldDefinition->getName()
        ), E_USER_WARNING);
      }
    }

    return $bundle;
  }

  /**
   * Returns the value of a setting for the entity reference selection handler.
   *
   * @param string $setting_name
   *   The setting name.
   *
   * @return mixed
   *   The setting value.
   */
  protected function getSelectionHandlerSetting($setting_name) {
    $settings = $this->getFieldSetting('handler_settings');
    return $settings[$setting_name] ?? NULL;
  }

  /**
   * Returns the options for the match operator.
   *
   * @return array
   *   List of options.
   */
  protected function getMatchOperatorOptions() {
    return [
      'STARTS_WITH' => $this->t('Starts with'),
      'CONTAINS' => $this->t('Contains'),
    ];
  }

}
