<?php

namespace Drupal\Core\DependencyInjection;

use Drupal\Component\DependencyInjection\Container as DrupalContainer;

/**
 * Extends the Drupal container to set the service ID on the created object.
 */
class Container extends DrupalContainer {

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    assert(FALSE, 'The container was serialized.');
    return array_keys(get_object_vars($this));
  }

}
