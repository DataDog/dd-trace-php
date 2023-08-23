<?php

namespace Drupal\datadog\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for datadog routes.
 */
class DatadogController extends ControllerBase {

    public function simple() {
        // https://www.drupal.org/project/drupal/issues/2559491
        return [
            '#markup' => 'simple',
        ];
    }

    public function simpleView() {
        return [
            '#theme' => 'datadog',
            '#test_var' => $this->t('Simple View'),
        ];
    }

    public function error() {
        throw new \Exception('Controller error');
    }
}
