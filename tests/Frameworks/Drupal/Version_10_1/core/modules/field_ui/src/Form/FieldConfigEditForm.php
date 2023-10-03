<?php

namespace Drupal\field_ui\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\Core\Url;
use Drupal\field\FieldConfigInterface;
use Drupal\field_ui\FieldUI;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for the field settings form.
 *
 * @internal
 */
class FieldConfigEditForm extends EntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\field\FieldConfigInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * Constructs a new FieldConfigDeleteForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The type data manger.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, protected TypedDataManagerInterface $typedDataManager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('typed_data_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $field_storage = $this->entity->getFieldStorageDefinition();
    $bundles = $this->entityTypeBundleInfo->getBundleInfo($this->entity->getTargetEntityTypeId());

    $form_title = $this->t('%field settings for %bundle', [
      '%field' => $this->entity->getLabel(),
      '%bundle' => $bundles[$this->entity->getTargetBundle()]['label'],
    ]);
    $form['#title'] = $form_title;

    if ($field_storage->isLocked()) {
      $form['locked'] = [
        '#markup' => $this->t('The field %field is locked and cannot be edited.', ['%field' => $this->entity->getLabel()]),
      ];
      return $form;
    }

    // Build the configurable field values.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $this->entity->getLabel() ?: $field_storage->getName(),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#weight' => -20,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Help text'),
      '#default_value' => $this->entity->getDescription(),
      '#rows' => 5,
      '#description' => $this->t('Instructions to present to the user below this field on the editing form.<br />Allowed HTML tags: @tags', ['@tags' => FieldFilteredMarkup::displayAllowedTags()]) . '<br />' . $this->t('This field supports tokens.'),
      '#weight' => -10,
    ];

    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Required field'),
      '#default_value' => $this->entity->isRequired(),
      '#weight' => -5,
    ];

    // Create an arbitrary entity object (used by the 'default value' widget).
    $ids = (object) [
      'entity_type' => $this->entity->getTargetEntityTypeId(),
      'bundle' => $this->entity->getTargetBundle(),
      'entity_id' => NULL,
    ];
    $form['#entity'] = _field_create_entity_from_ids($ids);
    $items = $this->getTypedData($form['#entity']);
    $item = $items->first() ?: $items->appendItem();

    // Add field settings for the field type and a container for third party
    // settings that modules can add to via hook_form_FORM_ID_alter().
    $form['settings'] = [
      '#tree' => TRUE,
      '#weight' => 10,
    ];
    $form['settings'] += $item->fieldSettingsForm($form, $form_state);
    $form['third_party_settings'] = [
      '#tree' => TRUE,
      '#weight' => 11,
    ];

    // Create a new instance of typed data for the field to ensure that default
    // value widget is always rendered from a clean state.
    $items = $this->getTypedData($form['#entity']);

    // Add handling for default value.
    if ($element = $items->defaultValuesForm($form, $form_state)) {
      $has_required = $this->hasAnyRequired($element);

      $element = array_merge($element, [
        '#type' => 'details',
        '#title' => $this->t('Default value'),
        '#open' => TRUE,
        '#tree' => TRUE,
        '#description' => $this->t('The default value for this field, used when creating new content.'),
        '#weight' => 12,
      ]);

      if (!$has_required) {
        $has_default_value = count($this->entity->getDefaultValue($form['#entity'])) > 0;
        $element['#states'] = [
          'invisible' => [
            ':input[name="set_default_value"]' => ['checked' => FALSE],
          ],
        ];
        $form['set_default_value'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Set default value'),
          '#default_value' => $has_default_value,
          '#description' => $this->t('Provide a pre-filled value for the editing form.'),
          '#weight' => $element['#weight'],
        ];
      }

      $form['default_value'] = $element;
    }

    return $form;
  }

  /**
   * A function to check if element contains any required elements.
   *
   * @param array $element
   *   An element to check.
   *
   * @return bool
   */
  private function hasAnyRequired(array $element) {
    $has_required = FALSE;
    foreach (Element::children($element) as $child) {
      if (isset($element[$child]['#required']) && $element[$child]['#required']) {
        $has_required = TRUE;
        break;
      }
      if (Element::children($element[$child])) {
        return $this->hasAnyRequired($element[$child]);
      }
    }

    return $has_required;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Save settings');

    if (!$this->entity->isNew()) {
      $target_entity_type = $this->entityTypeManager->getDefinition($this->entity->getTargetEntityTypeId());
      $route_parameters = [
        'field_config' => $this->entity->id(),
      ] + FieldUI::getRouteBundleParameter($target_entity_type, $this->entity->getTargetBundle());
      $url = new Url('entity.field_config.' . $target_entity_type->id() . '_field_delete_form', $route_parameters);

      if ($this->getRequest()->query->has('destination')) {
        $query = $url->getOption('query');
        $query['destination'] = $this->getRequest()->query->get('destination');
        $url->setOption('query', $query);
      }
      $actions['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('Delete'),
        '#url' => $url,
        '#access' => $this->entity->access('delete'),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }

    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Before proceeding validation, rebuild the entity to make sure it's
    // up-to-date. This is needed because element validators may update form
    // state, and other validators use the entity for validating the field.
    // @todo remove in https://www.drupal.org/project/drupal/issues/3372934.
    $this->entity = $this->buildEntity($form, $form_state);

    if (isset($form['default_value']) && (!isset($form['set_default_value']) || $form_state->getValue('set_default_value'))) {
      $items = $this->getTypedData($form['#entity']);
      $items->defaultValuesFormValidate($form['default_value'], $form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Handle the default value.
    $default_value = [];
    if (isset($form['default_value']) && (!isset($form['set_default_value']) || $form_state->getValue('set_default_value'))) {
      $items = $this->getTypedData($form['#entity']);
      $default_value = $items->defaultValuesFormSubmit($form['default_value'], $form, $form_state);
    }
    $this->entity->setDefaultValue($default_value);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $this->entity->save();

    $this->messenger()->addStatus($this->t('Saved %label configuration.', ['%label' => $this->entity->getLabel()]));

    $request = $this->getRequest();
    if (($destinations = $request->query->all('destinations')) && $next_destination = FieldUI::getNextDestination($destinations)) {
      $request->query->remove('destinations');
      $form_state->setRedirectUrl($next_destination);
    }
    else {
      $form_state->setRedirectUrl(FieldUI::getOverviewRouteInfo($this->entity->getTargetEntityTypeId(), $this->entity->getTargetBundle()));
    }
  }

  /**
   * The _title_callback for the field settings form.
   *
   * @param \Drupal\field\FieldConfigInterface $field_config
   *   The field.
   *
   * @return string
   *   The label of the field.
   */
  public function getTitle(FieldConfigInterface $field_config) {
    return $field_config->label();
  }

  /**
   * Gets typed data object for the field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $parent
   *   The parent entity that the field is attached to.
   *
   * @return \Drupal\Core\TypedData\TypedDataInterface
   */
  private function getTypedData(FieldableEntityInterface $parent): TypedDataInterface {
    $entity_adapter = EntityAdapter::createFromEntity($parent);
    return $this->typedDataManager->create($this->entity, $this->entity->getDefaultValue($parent), $this->entity->getName(), $entity_adapter);
  }

}
