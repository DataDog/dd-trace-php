<?php

namespace Drupal\migrate\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\DeprecatedServicePropertyTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin manager for migrate destination plugins.
 *
 * @see \Drupal\migrate\Plugin\MigrateDestinationInterface
 * @see \Drupal\migrate\Plugin\migrate\destination\DestinationBase
 * @see \Drupal\migrate\Annotation\MigrateDestination
 * @see plugin_api
 *
 * @ingroup migration
 */
class MigrateDestinationPluginManager extends MigratePluginManager {
  use DeprecatedServicePropertyTrait;

  /**
   * {@inheritdoc}
   */
  protected $deprecatedProperties = ['entityManager' => 'entity.manager'];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a MigrateDestinationPluginManager object.
   *
   * @param string $type
   *   The type of the plugin: row, source, process, destination, entity_field,
   *   id_map.
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param string $annotation
   *   (optional) The annotation class name. Defaults to
   *   'Drupal\migrate\Annotation\MigrateDestination'.
   */
  public function __construct($type, \Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, $annotation = 'Drupal\migrate\Annotation\MigrateDestination') {
    parent::__construct($type, $namespaces, $cache_backend, $module_handler, $annotation);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   *
   * A specific createInstance method is necessary to pass the migration on.
   */
  public function createInstance($plugin_id, array $configuration = [], MigrationInterface $migration = NULL) {
    if (substr($plugin_id, 0, 7) == 'entity:' && !$this->entityTypeManager->getDefinition(substr($plugin_id, 7), FALSE)) {
      $plugin_id = 'null';
    }
    return parent::createInstance($plugin_id, $configuration, $migration);
  }

}
