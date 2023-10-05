<?php

namespace Drupal\Core\Entity;

use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;

/**
 * A base entity storage class.
 */
abstract class EntityStorageBase extends EntityHandlerBase implements EntityStorageInterface, EntityHandlerInterface {

  /**
   * Entity type ID for this storage.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * Information about the entity type.
   *
   * The following code returns the same object:
   * @code
   * \Drupal::entityTypeManager()->getDefinition($this->entityTypeId)
   * @endcode
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityType;

  /**
   * Name of the entity's ID field in the entity database table.
   *
   * @var string
   */
  protected $idKey;

  /**
   * Name of entity's UUID database table field, if it supports UUIDs.
   *
   * Has the value FALSE if this entity does not use UUIDs.
   *
   * @var string
   */
  protected $uuidKey;

  /**
   * The name of the entity langcode property.
   *
   * @var string
   */
  protected $langcodeKey;

  /**
   * The UUID service.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected $uuidService;

  /**
   * Name of the base entity class.
   *
   * This is a private property since it's not meant to be set by child classes.
   * It holds the name of the entity class defined in the entity type that is
   * passed in to the constructor when instantiating an entity storage class.
   *
   * Normally, the entity class is defined via an annotation when defining an
   * entity type, via hook_entity_bundle_info() or via
   * hook_entity_bundle_info_alter(). However, due to how this property works,
   * the entity class can also be controlled via hook_entity_type_alter().
   *
   * @var string|null
   */
  private $baseEntityClass;

  /**
   * The memory cache.
   *
   * @var \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface
   */
  protected $memoryCache;

  /**
   * The memory cache tag.
   *
   * @var string
   */
  protected $memoryCacheTag;

  /**
   * Constructs an EntityStorageBase instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface $memory_cache
   *   The memory cache.
   */
  public function __construct(EntityTypeInterface $entity_type, MemoryCacheInterface $memory_cache) {
    $this->entityTypeId = $entity_type->id();
    $this->entityType = $entity_type;
    $this->baseEntityClass = $entity_type->getClass();
    $this->idKey = $this->entityType->getKey('id');
    $this->uuidKey = $this->entityType->getKey('uuid');
    $this->langcodeKey = $this->entityType->getKey('langcode');
    $this->memoryCache = $memory_cache;
    $this->memoryCacheTag = 'entity.memory_cache:' . $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityClass(?string $bundle = NULL): string {
    return $this->baseEntityClass;
  }

  /**
   * Warns subclasses not to directly access the deprecated entityClass property.
   *
   * @param string $name
   *   The name of the property to get.
   *
   * @todo Remove this in Drupal 10.
   * @see https://www.drupal.org/project/drupal/issues/3244802
   */
  public function __get($name) {
    if ($name === 'entityClass') {
      @trigger_error('Accessing the entityClass property directly is deprecated in drupal:9.3.0. Use ::getEntityClass() instead. See https://www.drupal.org/node/3191609', E_USER_DEPRECATED);
      return $this->getEntityClass();
    }
  }

  /**
   * Warns subclasses not to directly set the deprecated entityClass property.
   *
   * @param string $name
   *   The name of the property to set.
   * @param mixed $value
   *   The value to use.
   *
   * @todo Remove this in Drupal 10.
   * @see https://www.drupal.org/project/drupal/issues/3244802
   */
  public function __set(string $name, $value): void {
    if ($name === 'entityClass') {
      @trigger_error('Setting the entityClass property directly is deprecated in drupal:9.3.0 and has no effect in drupal:10.0.0. See https://www.drupal.org/node/3191609', E_USER_DEPRECATED);
      $this->baseEntityClass = $value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId() {
    return $this->entityTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType() {
    return $this->entityType;
  }

  /**
   * Builds the cache ID for the passed in entity ID.
   *
   * @param int $id
   *   Entity ID for which the cache ID should be built.
   *
   * @return string
   *   Cache ID that can be passed to the cache backend.
   */
  protected function buildCacheId($id) {
    return "values:{$this->entityTypeId}:$id";
  }

  /**
   * {@inheritdoc}
   */
  public function loadUnchanged($id) {
    $this->resetCache([$id]);
    return $this->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function resetCache(array $ids = NULL) {
    if ($this->entityType->isStaticallyCacheable() && isset($ids)) {
      foreach ($ids as $id) {
        $this->memoryCache->delete($this->buildCacheId($id));
      }
    }
    else {
      // Call the backend method directly.
      $this->memoryCache->invalidateTags([$this->memoryCacheTag]);
    }
  }

  /**
   * Gets entities from the static cache.
   *
   * @param array $ids
   *   If not empty, return entities that match these IDs.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Array of entities from the entity cache, keyed by entity ID.
   */
  protected function getFromStaticCache(array $ids) {
    $entities = [];
    // Load any available entities from the internal cache.
    if ($this->entityType->isStaticallyCacheable()) {
      foreach ($ids as $id) {
        if ($cached = $this->memoryCache->get($this->buildCacheId($id))) {
          $entities[$id] = $cached->data;
        }
      }
    }
    return $entities;
  }

  /**
   * Stores entities in the static entity cache.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   Entities to store in the cache.
   */
  protected function setStaticCache(array $entities) {
    if ($this->entityType->isStaticallyCacheable()) {
      foreach ($entities as $entity) {
        $this->memoryCache->set($this->buildCacheId($entity->id()), $entity, MemoryCacheInterface::CACHE_PERMANENT, [$this->memoryCacheTag]);
      }
    }
  }

  /**
   * Invokes a hook on behalf of the entity.
   *
   * @param string $hook
   *   One of 'create', 'presave', 'insert', 'update', 'predelete', 'delete', or
   *   'revision_delete'.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   */
  protected function invokeHook($hook, EntityInterface $entity) {
    // Invoke the hook.
    $this->moduleHandler()->invokeAll($this->entityTypeId . '_' . $hook, [$entity]);
    // Invoke the respective entity-level hook.
    $this->moduleHandler()->invokeAll('entity_' . $hook, [$entity]);
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $values = []) {
    $entity_class = $this->getEntityClass();
    $entity_class::preCreate($this, $values);

    // Assign a new UUID if there is none yet.
    if ($this->uuidKey && $this->uuidService && !isset($values[$this->uuidKey])) {
      $values[$this->uuidKey] = $this->uuidService->generate();
    }

    $entity = $this->doCreate($values);
    $entity->enforceIsNew();

    $entity->postCreate($this);

    // Modules might need to add or change the data initially held by the new
    // entity object, for instance to fill-in default values.
    $this->invokeHook('create', $entity);

    return $entity;
  }

  /**
   * Performs storage-specific creation of entities.
   *
   * @param array $values
   *   An array of values to set, keyed by property name.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   */
  protected function doCreate(array $values) {
    $entity_class = $this->getEntityClass();
    return new $entity_class($values, $this->entityTypeId);
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    assert(!is_null($id), sprintf('Cannot load the "%s" entity with NULL ID.', $this->entityTypeId));
    $entities = $this->loadMultiple([$id]);
    return $entities[$id] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple(array $ids = NULL) {
    $entities = [];
    $preloaded_entities = [];

    // Create a new variable which is either a prepared version of the $ids
    // array for later comparison with the entity cache, or FALSE if no $ids
    // were passed. The $ids array is reduced as items are loaded from cache,
    // and we need to know if it is empty for this reason to avoid querying the
    // database when all requested entities are loaded from cache.
    $flipped_ids = $ids ? array_flip($ids) : FALSE;
    // Try to load entities from the static cache, if the entity type supports
    // static caching.
    if ($ids) {
      $entities += $this->getFromStaticCache($ids);
      // If any entities were loaded, remove them from the IDs still to load.
      $ids = array_keys(array_diff_key($flipped_ids, $entities));
    }

    // Try to gather any remaining entities from a 'preload' method. This method
    // can invoke a hook to be used by modules that need, for example, to swap
    // the default revision of an entity with a different one. Even though the
    // base entity storage class does not actually invoke any preload hooks, we
    // need to call the method here so we can add the pre-loaded entity objects
    // to the static cache below. If all the entities were fetched from the
    // static cache, skip this step.
    if ($ids === NULL || $ids) {
      $preloaded_entities = $this->preLoad($ids);
    }
    if (!empty($preloaded_entities)) {
      $entities += $preloaded_entities;

      // If any entities were pre-loaded, remove them from the IDs still to
      // load.
      $ids = array_keys(array_diff_key($flipped_ids, $entities));

      // Add pre-loaded entities to the cache.
      $this->setStaticCache($preloaded_entities);
    }

    // Load any remaining entities from the database. This is the case if $ids
    // is set to NULL (so we load all entities) or if there are any IDs left to
    // load.
    if ($ids === NULL || $ids) {
      $queried_entities = $this->doLoadMultiple($ids);
    }

    // Pass all entities loaded from the database through $this->postLoad(),
    // which attaches fields (if supported by the entity type) and calls the
    // entity type specific load callback, for example hook_node_load().
    if (!empty($queried_entities)) {
      $this->postLoad($queried_entities);
      $entities += $queried_entities;

      // Add queried entities to the cache.
      $this->setStaticCache($queried_entities);
    }

    // Ensure that the returned array is ordered the same as the original
    // $ids array if this was passed in and remove any invalid IDs.
    if ($flipped_ids) {
      // Remove any invalid IDs from the array and preserve the order passed in.
      $flipped_ids = array_intersect_key($flipped_ids, $entities);
      $entities = array_replace($flipped_ids, $entities);
    }

    return $entities;
  }

  /**
   * Performs storage-specific loading of entities.
   *
   * Override this method to add custom functionality directly after loading.
   * This is always called, while self::postLoad() is only called when there are
   * actual results.
   *
   * @param array|null $ids
   *   (optional) An array of entity IDs, or NULL to load all entities.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Associative array of entities, keyed on the entity ID.
   */
  abstract protected function doLoadMultiple(array $ids = NULL);

  /**
   * Gathers entities from a 'preload' step.
   *
   * @param array|null &$ids
   *   If not empty, return entities that match these IDs. IDs that were found
   *   will be removed from the list.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Associative array of entities, keyed by the entity ID.
   */
  protected function preLoad(array &$ids = NULL) {
    return [];
  }

  /**
   * Attaches data to entities upon loading.
   *
   * If there are multiple bundle classes involved, each one gets a sub array
   * with only the entities of the same bundle. If there's only a single bundle,
   * the entity's postLoad() method will get a copy of the original $entities
   * array.
   *
   * @param array $entities
   *   Associative array of query results, keyed on the entity ID.
   */
  protected function postLoad(array &$entities) {
    $entities_by_class = $this->getEntitiesByClass($entities);

    // Invoke entity class specific postLoad() methods. If there's only a single
    // class involved, we want to pass in the original $entities array. For
    // example, to provide backwards compatibility with the legacy behavior of
    // the deprecated user_roles() method, \Drupal\user\Entity\Role::postLoad()
    // sorts the array to enforce role weights. We have to let it manipulate the
    // final array, not a subarray. However if there are multiple bundle classes
    // involved, we only want to pass each one the entities that match.
    if (count($entities_by_class) === 1) {
      $entity_class = array_key_first($entities_by_class);
      $entity_class::postLoad($this, $entities);
    }
    else {
      foreach ($entities_by_class as $entity_class => &$items) {
        $entity_class::postLoad($this, $items);
      }
    }
    $this->moduleHandler()->invokeAllWith('entity_load', function (callable $hook, string $module) use (&$entities) {
      $hook($entities, $this->entityTypeId);
    });
    $this->moduleHandler()->invokeAllWith($this->entityTypeId . '_load', function (callable $hook, string $module) use (&$entities) {
      $hook($entities);
    });
  }

  /**
   * Maps from storage records to entity objects.
   *
   * @param array $records
   *   Associative array of query results, keyed on the entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of entity objects implementing the EntityInterface.
   */
  protected function mapFromStorageRecords(array $records) {
    $entities = [];
    foreach ($records as $record) {
      $entity_class = $this->getEntityClass();
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = new $entity_class($record, $this->entityTypeId);
      $entities[$entity->id()] = $entity;
    }
    return $entities;
  }

  /**
   * Determines if this entity already exists in storage.
   *
   * @param int|string $id
   *   The original entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE if this entity exists in storage, FALSE otherwise.
   */
  abstract protected function has($id, EntityInterface $entity);

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    if (!$entities) {
      // If no entities were passed, do nothing.
      return;
    }

    $entities_by_class = $this->getEntitiesByClass($entities);

    // Allow code to run before deleting.
    foreach ($entities_by_class as $entity_class => &$items) {
      $entity_class::preDelete($this, $items);
      foreach ($items as $entity) {
        $this->invokeHook('predelete', $entity);
      }

      // Perform the delete and reset the static cache for the deleted entities.
      $this->doDelete($items);
      $this->resetCache(array_keys($items));

      // Allow code to run after deleting.
      $entity_class::postDelete($this, $items);
      foreach ($items as $entity) {
        $this->invokeHook('delete', $entity);
      }
    }
  }

  /**
   * Performs storage-specific entity deletion.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   An array of entity objects to delete.
   */
  abstract protected function doDelete($entities);

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $entity) {
    // Track if this entity is new.
    $is_new = $entity->isNew();

    // Execute presave logic and invoke the related hooks.
    $id = $this->doPreSave($entity);

    // Perform the save and reset the static cache for the changed entity.
    $return = $this->doSave($id, $entity);

    // Execute post save logic and invoke the related hooks.
    $this->doPostSave($entity, !$is_new);

    return $return;
  }

  /**
   * Performs presave entity processing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   *
   * @return int|string
   *   The processed entity identifier.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   If the entity identifier is invalid.
   */
  protected function doPreSave(EntityInterface $entity) {
    $id = $entity->id();

    // Track the original ID.
    if ($entity->getOriginalId() !== NULL) {
      $id = $entity->getOriginalId();
    }

    // Track if this entity exists already.
    $id_exists = $this->has($id, $entity);

    // A new entity should not already exist.
    if ($id_exists && $entity->isNew()) {
      throw new EntityStorageException("'{$this->entityTypeId}' entity with ID '$id' already exists.");
    }

    // Load the original entity, if any.
    if ($id_exists && !isset($entity->original)) {
      $entity->original = $this->loadUnchanged($id);
    }

    // Allow code to run before saving.
    $entity->preSave($this);
    $this->invokeHook('presave', $entity);

    return $id;
  }

  /**
   * Performs storage-specific saving of the entity.
   *
   * @param int|string $id
   *   The original entity ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to save.
   *
   * @return bool|int
   *   If the record insert or update failed, returns FALSE. If it succeeded,
   *   returns SAVED_NEW or SAVED_UPDATED, depending on the operation performed.
   */
  abstract protected function doSave($id, EntityInterface $entity);

  /**
   * Performs post save entity processing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   * @param bool $update
   *   Specifies whether the entity is being updated or created.
   */
  protected function doPostSave(EntityInterface $entity, $update) {
    $this->resetCache([$entity->id()]);

    // The entity is no longer new.
    $entity->enforceIsNew(FALSE);

    // Allow code to run after saving.
    $entity->postSave($this, $update);
    $this->invokeHook($update ? 'update' : 'insert', $entity);

    // After saving, this is now the "original entity", and subsequent saves
    // will be updates instead of inserts, and updates must always be able to
    // correctly identify the original entity.
    $entity->setOriginalId($entity->id());

    unset($entity->original);
  }

  /**
   * {@inheritdoc}
   */
  public function restore(EntityInterface $entity) {
    // The restore process does not invoke any pre or post-save operations.
    $this->doSave($entity->id(), $entity);
  }

  /**
   * Builds an entity query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $entity_query
   *   EntityQuery instance.
   * @param array $values
   *   An associative array of properties of the entity, where the keys are the
   *   property names and the values are the values those properties must have.
   */
  protected function buildPropertyQuery(QueryInterface $entity_query, array $values) {
    foreach ($values as $name => $value) {
      // Cast scalars to array so we can consistently use an IN condition.
      $entity_query->condition($name, (array) $value, 'IN');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $values = []) {
    // Build a query to fetch the entity IDs.
    $entity_query = $this->getQuery();
    $entity_query->accessCheck(FALSE);
    $this->buildPropertyQuery($entity_query, $values);
    $result = $entity_query->execute();
    return $result ? $this->loadMultiple($result) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function hasData() {
    return (bool) $this->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getQuery($conjunction = 'AND') {
    return \Drupal::service($this->getQueryServiceName())->get($this->entityType, $conjunction);
  }

  /**
   * {@inheritdoc}
   */
  public function getAggregateQuery($conjunction = 'AND') {
    return \Drupal::service($this->getQueryServiceName())->getAggregate($this->entityType, $conjunction);
  }

  /**
   * Gets the name of the service for the query for this entity storage.
   *
   * @return string
   *   The name of the service for the query for this entity storage.
   */
  abstract protected function getQueryServiceName();

  /**
   * Indexes the given array of entities by their class name and ID.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $entities
   *   The array of entities to index.
   *
   * @return \Drupal\Core\Entity\EntityInterface[][]
   *   An array of the passed-in entities, indexed by their class name and ID.
   */
  protected function getEntitiesByClass(array $entities): array {
    $entity_classes = [];
    foreach ($entities as $entity) {
      $entity_classes[get_class($entity)][$entity->id()] = $entity;
    }
    return $entity_classes;
  }

}
