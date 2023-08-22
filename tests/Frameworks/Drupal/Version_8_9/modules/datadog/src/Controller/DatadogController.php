<?php

namespace Drupal\datadog\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for datadog routes.
 */
class DatadogController extends ControllerBase {

    public function simple() {
        return "simple";
    }

    public function simpleView() {

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
