<?php

namespace Drupal\Component\Plugin\Exception;

/**
 * Plugin exception class to be thrown when a plugin ID could not be found.
 */
class PluginNotFoundException extends PluginException {

  /**
   * Construct a PluginNotFoundException exception.
   *
   * @param string $plugin_id
   *   The plugin ID that was not found.
   * @param string $message
   *   The exception message.
   * @param int $code
   *   The exception code.
   * @param \Exception|null $previous
   *   The previous throwable used for exception chaining.
   *
   * @see \Exception
   */
  public function __construct($plugin_id, $message = '', $code = 0, \Exception $previous = NULL) {
    if (empty($message)) {
      $message = sprintf("Plugin ID '%s' was not found.", $plugin_id);
    }
    parent::__construct($message, $code, $previous);
  }

}
