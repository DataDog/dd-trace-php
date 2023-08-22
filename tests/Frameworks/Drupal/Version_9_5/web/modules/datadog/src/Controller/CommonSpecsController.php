<?php

namespace Drupal\datadog\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Datadog routes.
 */
class CommonSpecsController extends ControllerBase {

  /**
   * Simple: Returns the "simple" string
   */
  public function build() {
    return "simple";
  }

  public function simple_view() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('Simple View'),
    ];

    return $build;
  }

  public function error() {
    throw new \Exception('Controller error');
  }

}
