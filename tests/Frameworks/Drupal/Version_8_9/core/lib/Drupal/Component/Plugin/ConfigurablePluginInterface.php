<?php

namespace Drupal\Component\Plugin;

/**
 * Provides an interface for a configurable plugin.
 *
 * @deprecated Drupal\Component\Plugin\ConfigurablePluginInterface is deprecated
 * in Drupal 8.7.0 and will be removed before Drupal 9.0.0. You should implement
 * ConfigurableInterface and/or DependentPluginInterface directly as needed. If
 * you implement ConfigurableInterface you may choose to implement
 * ConfigurablePluginInterface in Drupal 8 as well for maximum compatibility,
 * however this must be removed prior to Drupal 9.
 *
 * @see https://www.drupal.org/node/2946161
 *
 * @ingroup plugin_api
 */
interface ConfigurablePluginInterface extends DependentPluginInterface {

  /**
   * Gets this plugin's configuration.
   *
   * @return array
   *   An array of this plugin's configuration.
   */
  public function getConfiguration();

  /**
   * Sets the configuration for this plugin instance.
   *
   * @param array $configuration
   *   An associative array containing the plugin's configuration.
   */
  public function setConfiguration(array $configuration);

  /**
   * Gets default configuration for this plugin.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  public function defaultConfiguration();

}
