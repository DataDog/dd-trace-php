<?php

namespace Drupal\taxonomy;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Base for handler for taxonomy term edit forms.
 *
 * @internal
 */
class TermForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $term = $this->entity;
    $vocab_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    /** @var \Drupal\taxonomy\TermStorageInterface $taxonomy_storage */
    $taxonomy_storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $vocabulary = $vocab_storage->load($term->bundle());

    $parent = $this->getParentIds($term);
    $form_state->set(['taxonomy', 'parent'], $parent);
    $form_state->set(['taxonomy', 'vocabulary'], $vocabulary);

    $form['relations'] = [
      '#type' => 'details',
      '#title' => $this->t('Relations'),
      '#open' => $taxonomy_storage->getVocabularyHierarchyType($vocabulary->id()) == VocabularyInterface::HIERARCHY_MULTIPLE,
      '#weight' => 10,
    ];

    // \Drupal\taxonomy\TermStorageInterface::loadTree() and
    // \Drupal\taxonomy\TermStorageInterface::loadParents() may contain large
    // numbers of items so we check for taxonomy.settings:override_selector
    // before loading the full vocabulary. Contrib modules can then intercept
    // before hook_form_alter to provide scalable alternatives.
    if (!$this->config('taxonomy.settings')->get('override_selector')) {
      $exclude = [];
      if (!$term->isNew()) {
        $children = $taxonomy_storage->loadTree($vocabulary->id(), $term->id());

        // A term can't be the child of itself, nor of its children.
        foreach ($children as $child) {
          $exclude[] = $child->tid;
        }
        $exclude[] = $term->id();
      }

      $tree = $taxonomy_storage->loadTree($vocabulary->id());
      $options = ['<' . $this->t('root') . '>'];
      if (empty($parent)) {
        $parent = [0];
      }

      foreach ($tree as $item) {
        if (!in_array($item->tid, $exclude)) {
          $options[$item->tid] = str_repeat('-', $item->depth) . $item->name;
        }
      }
    }
    else {
      $options = ['<' . $this->t('root') . '>'];
      $parent = [0];
    }

    $form['relations']['parent'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent terms'),
      '#options' => $options,
      '#default_value' => $parent,
      '#multiple' => TRUE,
    ];

    $form['relations']['weight'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Weight'),
      '#size' => 6,
      '#default_value' => $term->getWeight(),
      '#description' => $this->t('Terms are displayed in ascending order by weight.'),
      '#required' => TRUE,
    ];

    $form['vid'] = [
      '#type' => 'value',
      '#value' => $vocabulary->id(),
    ];

    $form['tid'] = [
      '#type' => 'value',
      '#value' => $term->id(),
    ];

    return parent::form($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $element = parent::actions($form, $form_state);
    if (!$this->getRequest()->query->has('destination')) {
      $element['overview'] = [
        '#type' => 'submit',
        '#value' => $this->t('Save and go to list'),
        '#weight' => 20,
        '#submit' => array_merge($element['submit']['#submit'], ['::overview']),
        '#access' => $this->currentUser()->hasPermission('access taxonomy overview'),
      ];
    }

    return $element;
  }

  /**
   * Form submission handler for the 'overview' action.
   *
   * @param array[] $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function overview(array $form, FormStateInterface $form_state): void {
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')
      ->load($form_state->getValue('vid'));
    $form_state->setRedirectUrl($vocabulary->toUrl('overview-form'));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Ensure numeric values.
    if ($form_state->hasValue('weight') && !is_numeric($form_state->getValue('weight'))) {
      $form_state->setErrorByName('weight', $this->t('Weight value must be numeric.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    $term = parent::buildEntity($form, $form_state);

    // Prevent leading and trailing spaces in term names.
    $term->setName(trim($term->getName()));

    // Assign parents with proper delta values starting from 0.
    $term->parent = array_values($form_state->getValue('parent'));

    return $term;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditedFieldNames(FormStateInterface $form_state) {
    return array_merge(['parent', 'weight'], parent::getEditedFieldNames($form_state));
  }

  /**
   * {@inheritdoc}
   */
  protected function flagViolations(EntityConstraintViolationListInterface $violations, array $form, FormStateInterface $form_state) {
    // Manually flag violations of fields not handled by the form display. This
    // is necessary as entity form displays only flag violations for fields
    // contained in the display.
    // @see ::form()
    foreach ($violations->getByField('parent') as $violation) {
      $form_state->setErrorByName('parent', $violation->getMessage());
    }
    foreach ($violations->getByField('weight') as $violation) {
      $form_state->setErrorByName('weight', $violation->getMessage());
    }

    parent::flagViolations($violations, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $term = $this->entity;

    $result = $term->save();

    $edit_link = $term->toLink($this->t('Edit'), 'edit-form')->toString();
    $view_link = $term->toLink()->toString();
    switch ($result) {
      case SAVED_NEW:
        $this->messenger()->addStatus($this->t('Created new term %term.', ['%term' => $view_link]));
        $this->logger('taxonomy')->notice('Created new term %term.', ['%term' => $term->getName(), 'link' => $edit_link]);
        break;

      case SAVED_UPDATED:
        $this->messenger()->addStatus($this->t('Updated term %term.', ['%term' => $view_link]));
        $this->logger('taxonomy')->notice('Updated term %term.', ['%term' => $term->getName(), 'link' => $edit_link]);
        $form_state->setRedirect('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->id()]);
        break;
    }

    $current_parent_count = count($form_state->getValue('parent'));
    // Root doesn't count if it's the only parent.
    if ($current_parent_count == 1 && $form_state->hasValue(['parent', 0])) {
      $form_state->setValue('parent', []);
    }

    $form_state->setValue('tid', $term->id());
    $form_state->set('tid', $term->id());
  }

  /**
   * Returns term parent IDs, including the root.
   *
   * @param \Drupal\taxonomy\TermInterface $term
   *   The taxonomy term entity.
   *
   * @return array
   *   A list if parent term IDs.
   */
  protected function getParentIds(TermInterface $term): array {
    $parent = [];
    // Get the parent directly from the term as
    // \Drupal\taxonomy\TermStorageInterface::loadParents() excludes the root.
    foreach ($term->get('parent') as $item) {
      $parent[] = (int) $item->target_id;
    }
    return $parent;
  }

}
