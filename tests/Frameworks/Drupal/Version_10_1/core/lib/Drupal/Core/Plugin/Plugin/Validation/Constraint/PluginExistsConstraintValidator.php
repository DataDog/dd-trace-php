<?php

declare(strict_types = 1);

namespace Drupal\Core\Plugin\Plugin\Validation\Constraint;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the PluginExists constraint.
 */
class PluginExistsConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $plugin_id, Constraint $constraint) {
    assert($constraint instanceof PluginExistsConstraint);

    $definition = $constraint->pluginManager->getDefinition($plugin_id, FALSE);
    if (empty($definition)) {
      $this->context->addViolation($constraint->unknownPluginMessage, [
        '@plugin_id' => $plugin_id,
      ]);
      return;
    }

    // If we don't need to validate the plugin class's interface, we're done.
    if (empty($constraint->interface)) {
      return;
    }

    if (!is_a(DefaultFactory::getPluginClass($plugin_id, $definition), $constraint->interface, TRUE)) {
      $this->context->addViolation($constraint->invalidInterfaceMessage, [
        '@plugin_id' => $plugin_id,
        '@interface' => $constraint->interface,
      ]);
    }
  }

}
