<?php

namespace Drupal\media_library;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Service which builds the media library.
 *
 * @internal
 *   This service is an internal part of the modal media library dialog and
 *   does not provide any extension points.
 */
class MediaLibraryUiBuilder {

  use StringTranslationTrait;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The currently active request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The views executable factory.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected $viewsExecutableFactory;

  /**
   * The media library opener resolver.
   *
   * @var \Drupal\media_library\OpenerResolverInterface
   */
  protected $openerResolver;

  /**
   * Constructs a MediaLibraryUiBuilder instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\views\ViewExecutableFactory $views_executable_factory
   *   The views executable factory.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The currently active request object.
   * @param \Drupal\media_library\OpenerResolverInterface $opener_resolver
   *   The opener resolver.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, ViewExecutableFactory $views_executable_factory, FormBuilderInterface $form_builder, OpenerResolverInterface $opener_resolver = NULL) {
    $this->entityTypeManager = $entity_type_manager;
    $this->request = $request_stack->getCurrentRequest();
    $this->viewsExecutableFactory = $views_executable_factory;
    $this->formBuilder = $form_builder;
    if (!$opener_resolver) {
      @trigger_error('The media_library.opener_resolver service must be passed to ' . __METHOD__ . ' and will be required before Drupal 9.0.0.', E_USER_DEPRECATED);
      $opener_resolver = \Drupal::service('media_library.opener_resolver');
    }
    $this->openerResolver = $opener_resolver;
  }

  /**
   * Get media library dialog options.
   *
   * @return array
   *   The media library dialog options.
   */
  public static function dialogOptions() {
    return [
      'dialogClass' => 'media-library-widget-modal',
      'title' => t('Add or select media'),
      'height' => '75%',
      'width' => '75%',
    ];
  }

  /**
   * Build the media library UI.
   *
   * @param \Drupal\media_library\MediaLibraryState $state
   *   (optional) The current state of the media library, derived from the
   *   current request.
   *
   * @return array
   *   The render array for the media library.
   */
  public function buildUi(MediaLibraryState $state = NULL) {
    if (!$state) {
      $state = MediaLibraryState::fromRequest($this->request);
    }
    // When navigating to a media type through the vertical tabs, we only want
    // to load the changed library content. This is not only more efficient, but
    // also provides a more accessible user experience for screen readers.
    if ($state->get('media_library_content') === '1') {
      return $this->buildLibraryContent($state);
    }
    else {
      return [
        '#theme' => 'media_library_wrapper',
        '#attributes' => [
          'id' => 'media-library-wrapper',
        ],
        'menu' => $this->buildMediaTypeMenu($state),
        'content' => $this->buildLibraryContent($state),
        // Attach the JavaScript for the media library UI. The number of
        // available slots needs to be added to make sure users can't select
        // more items than allowed.
        '#attached' => [
          'library' => ['media_library/ui'],
          'drupalSettings' => [
            'media_library' => [
              'selection_remaining' => $state->getAvailableSlots(),
            ],
          ],
        ],
      ];
    }
  }

  /**
   * Build the media library content area.
   *
   * @param \Drupal\media_library\MediaLibraryState $state
   *   The current state of the media library, derived from the current request.
   *
   * @return array
   *   The render array for the media library.
   */
  protected function buildLibraryContent(MediaLibraryState $state) {
    return [
      '#type' => 'container',
      '#theme_wrappers' => [
        'container__media_library_content',
      ],
      '#attributes' => [
        'id' => 'media-library-content',
      ],
      'form' => $this->buildMediaTypeAddForm($state),
      'view' => $this->buildMediaLibraryView($state),
    ];
  }

  /**
   * Check access to the media library.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   * @param \Drupal\media_library\MediaLibraryState $state
   *   (optional) The current state of the media library, derived from the
   *   current request.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function checkAccess(AccountInterface $account, MediaLibraryState $state = NULL) {
    if (!$state) {
      try {
        $state = MediaLibraryState::fromRequest($this->request);
      }
      catch (BadRequestHttpException $e) {
        return AccessResult::forbidden($e->getMessage());
      }
      catch (\InvalidArgumentException $e) {
        return AccessResult::forbidden($e->getMessage());
      }
    }
    // Deny access if the view or display are removed.
    $view = $this->entityTypeManager->getStorage('view')->load('media_library');
    if (!$view) {
      return AccessResult::forbidden('The media library view does not exist.')
        ->setCacheMaxAge(0);
    }
    if (!$view->getDisplay('widget')) {
      return AccessResult::forbidden('The media library widget display does not exist.')
        ->addCacheableDependency($view);
    }

    // The user must at least be able to view media in order to access the media
    // library.
    $can_view_media = AccessResult::allowedIfHasPermission($account, 'view media')
      ->addCacheableDependency($view);

    // Delegate any further access checking to the opener service nominated by
    // the media library state.
    return $this->openerResolver->get($state)->checkAccess($state, $account)
      ->andIf($can_view_media);
  }

  /**
   * Get the media type menu for the media library.
   *
   * @param \Drupal\media_library\MediaLibraryState $state
   *   The current state of the media library, derived from the current request.
   *
   * @return array
   *   The render array for the media type menu.
   */
  protected function buildMediaTypeMenu(MediaLibraryState $state) {
    // Add the menu for each type if we have more than 1 media type enabled for
    // the field.
    $allowed_type_ids = $state->getAllowedTypeIds();
    if (count($allowed_type_ids) <= 1) {
      return [];
    }

    // @todo: Add a class to the li element.
    //   https://www.drupal.org/project/drupal/issues/3029227
    $menu = [
      '#theme' => 'links__media_library_menu',
      '#links' => [],
      '#attributes' => [
        'class' => ['js-media-library-menu'],
      ],
    ];

    $allowed_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple($allowed_type_ids);

    $selected_type_id = $state->getSelectedTypeId();
    foreach ($allowed_types as $allowed_type_id => $allowed_type) {
      $link_state = MediaLibraryState::create($state->getOpenerId(), $state->getAllowedTypeIds(), $allowed_type_id, $state->getAvailableSlots(), $state->getOpenerParameters());
      // Add the 'media_library_content' parameter so the response will contain
      // only the updated content for the tab.
      // @see self::buildUi()
      $link_state->set('media_library_content', 1);

      $title = $allowed_type->label();
      $display_title = [
        '#markup' => $this->t('<span class="visually-hidden">Show </span>@title<span class="visually-hidden"> media</span>', ['@title' => $title]),
      ];
      if ($allowed_type_id === $selected_type_id) {
        $display_title = [
          '#markup' => $this->t('<span class="visually-hidden">Show </span>@title<span class="visually-hidden"> media</span><span class="active-tab visually-hidden"> (selected)</span>', ['@title' => $title]),
        ];
      }

      $menu['#links']['media-library-menu-' . $allowed_type_id] = [
        'title' => $display_title,
        'url' => Url::fromRoute('media_library.ui', [], [
          'query' => $link_state->all(),
        ]),
        'attributes' => [
          'role' => 'button',
          'data-title' => $title,
        ],
      ];
    }

    // Set the active menu item.
    $menu['#links']['media-library-menu-' . $selected_type_id]['attributes']['class'][] = 'active';

    return $menu;
  }

  /**
   * Get the add form for the selected media type.
   *
   * @param \Drupal\media_library\MediaLibraryState $state
   *   The current state of the media library, derived from the current request.
   *
   * @return array
   *   The render array for the media type add form.
   */
  protected function buildMediaTypeAddForm(MediaLibraryState $state) {
    $selected_type_id = $state->getSelectedTypeId();

    if (!$this->entityTypeManager->getAccessControlHandler('media')->createAccess($selected_type_id)) {
      return [];
    }

    $selected_type = $this->entityTypeManager->getStorage('media_type')->load($selected_type_id);
    $plugin_definition = $selected_type->getSource()->getPluginDefinition();

    if (empty($plugin_definition['forms']['media_library_add'])) {
      return [];
    }

    // After the form to add new media is submitted, we need to rebuild the
    // media library with a new instance of the media add form. The form API
    // allows us to do that by forcing empty user input.
    // @see \Drupal\Core\Form\FormBuilder::doBuildForm()
    $form_state = new FormState();
    if ($state->get('_media_library_form_rebuild')) {
      $form_state->setUserInput([]);
      $state->remove('_media_library_form_rebuild');
    }
    $form_state->set('media_library_state', $state);
    return $this->formBuilder->buildForm($plugin_definition['forms']['media_library_add'], $form_state);
  }

  /**
   * Get the media library view.
   *
   * @param \Drupal\media_library\MediaLibraryState $state
   *   The current state of the media library, derived from the current request.
   *
   * @return array
   *   The render array for the media library view.
   */
  protected function buildMediaLibraryView(MediaLibraryState $state) {
    // @todo Make the view configurable in
    //   https://www.drupal.org/project/drupal/issues/2971209
    $view = $this->entityTypeManager->getStorage('view')->load('media_library');
    $view_executable = $this->viewsExecutableFactory->get($view);
    $display_id = $state->get('views_display_id', 'widget');

    // Make sure the state parameters are set in the request so the view can
    // pass the parameters along in the pager, filters etc.
    $view_request = $view_executable->getRequest();
    $view_request->query->add($state->all());
    $view_executable->setRequest($view_request);

    $args = [$state->getSelectedTypeId()];

    // Make sure the state parameters are set in the request so the view can
    // pass the parameters along in the pager, filters etc.
    $request = $view_executable->getRequest();
    $request->query->add($state->all());
    $view_executable->setRequest($request);

    $view_executable->setDisplay($display_id);
    $view_executable->preExecute($args);
    $view_executable->execute($display_id);

    return $view_executable->buildRenderable($display_id, $args, FALSE);
  }

}
