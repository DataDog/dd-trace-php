<?php

namespace Drupal\field_ui\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides local action definitions for all entity bundles.
 */
class FieldUiLocalAction extends DeriverBase implements ContainerDeriverInterface {

  use StringTranslationTrait;

  /**
   * The route provider to load routes by name.
   */
  protected $routeProvider;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a FieldUiLocalAction object.
   *
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The route provider to load routes by name.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(RouteProviderInterface $route_provider, EntityTypeManagerInterface $entity_type_manager) {
    $this->routeProvider = $route_provider;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('router.route_provider'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $this->derivatives = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type->get('field_ui_base_route')) {
        $this->derivatives["field_storage_config_add_$entity_type_id"] = [
          'route_name' => "field_ui.field_storage_config_add_$entity_type_id",
          'title' => $this->t('Add field'),
          'appears_on' => ["entity.$entity_type_id.field_ui_fields"],
        ];
      }
    }

    foreach ($this->derivatives as &$entry) {
      $entry += $base_plugin_definition;
    }

    return $this->derivatives;
  }

}
