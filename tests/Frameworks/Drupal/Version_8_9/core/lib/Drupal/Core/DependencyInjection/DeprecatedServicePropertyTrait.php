<?php

namespace Drupal\Core\DependencyInjection;

/**
 * Provides a standard way to announce deprecated properties.
 */
trait DeprecatedServicePropertyTrait {

  /**
   * Allows to access deprecated/removed properties.
   *
   * This method must be public.
   */
  public function __get($name) {
    if (!isset($this->deprecatedProperties)) {
      throw new \LogicException('The deprecatedProperties property must be defined to use this trait.');
    }

    if (isset($this->deprecatedProperties[$name])) {
      $service_name = $this->deprecatedProperties[$name];
      $class_name = static::class;
      @trigger_error("The property $name ($service_name service) is deprecated in $class_name and will be removed before Drupal 9.0.0.", E_USER_DEPRECATED);
      return \Drupal::service($service_name);
    }
  }

}
