<?php

namespace Drupal\views_config_entity_test;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\views\EntityViewsDataInterface;

/**
 * Provides a view to override views data for config test entity types.
 */
class ViewsConfigEntityTestViewsData implements EntityViewsDataInterface {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    return [];
  }

  /**
   * @inheritDoc
   */
  public function getViewsTableForEntityType(EntityTypeInterface $entity_type) {
    return 'views_config_entity_test';
  }

}
