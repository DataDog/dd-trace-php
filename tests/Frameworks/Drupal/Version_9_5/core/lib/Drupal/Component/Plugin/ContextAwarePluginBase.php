<?php

namespace Drupal\Component\Plugin;

use Drupal\Component\Plugin\Context\ContextInterface;
use Drupal\Component\Plugin\Definition\ContextAwarePluginDefinitionInterface;
use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Plugin\Context\Context;
use Symfony\Component\Validator\ConstraintViolationList;

@trigger_error(__NAMESPACE__ . '\ContextAwarePluginBase is deprecated in drupal:9.1.0 and is removed from drupal:10.0.0 without replacement. See https://www.drupal.org/node/3120980', E_USER_DEPRECATED);

/**
 * Base class for plugins that are context aware.
 *
 * @deprecated in drupal:9.1.0 and is removed from drupal:10.0.0 without
 *   replacement.
 *
 * @see https://www.drupal.org/node/3120980
 */
abstract class ContextAwarePluginBase extends PluginBase implements ContextAwarePluginInterface {

  /**
   * The data objects representing the context of this plugin.
   *
   * @var \Drupal\Component\Plugin\Context\ContextInterface[]
   */
  protected $context = [];

  /**
   * Overrides \Drupal\Component\Plugin\PluginBase::__construct().
   *
   * Overrides the construction of context aware plugins to allow for
   * unvalidated constructor based injection of contexts.
   *
   * @param array $configuration
   *   The plugin configuration, i.e. an array with configuration values keyed
   *   by configuration option name. The special key 'context' may be used to
   *   initialize the defined contexts by setting it to an array of context
   *   values keyed by context names.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    $context_configuration = $configuration['context'] ?? [];
    unset($configuration['context']);

    parent::__construct($configuration, $plugin_id, $plugin_definition);

    if ($context_configuration) {
      @trigger_error('Passing context values to plugins via configuration is deprecated in drupal:9.1.0 and will be removed before drupal:10.0.0. Instead, call ::setContextValue() on the plugin itself. See https://www.drupal.org/node/3120980', E_USER_DEPRECATED);
    }
    $this->context = $this->createContextFromConfiguration($context_configuration);
  }

  /**
   * Creates context objects from any context mappings in configuration.
   *
   * @param array $context_configuration
   *   An associative array of context names and values.
   *
   * @return \Drupal\Component\Plugin\Context\ContextInterface[]
   *   An array of context objects.
   */
  protected function createContextFromConfiguration(array $context_configuration) {
    $contexts = [];
    foreach ($context_configuration as $key => $value) {
      $context_definition = $this->getContextDefinition($key);
      $contexts[$key] = new Context($context_definition, $value);
    }
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    $definition = $this->getPluginDefinition();
    if ($definition instanceof ContextAwarePluginDefinitionInterface) {
      return $definition->getContextDefinitions();
    }
    else {
      return !empty($definition['context_definitions']) ? $definition['context_definitions'] : [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition($name) {
    $definition = $this->getPluginDefinition();
    if ($definition instanceof ContextAwarePluginDefinitionInterface) {
      if ($definition->hasContextDefinition($name)) {
        return $definition->getContextDefinition($name);
      }
    }
    elseif (!empty($definition['context_definitions'][$name])) {
      return $definition['context_definitions'][$name];
    }
    throw new ContextException(sprintf("The %s context is not a valid context.", $name));
  }

  /**
   * {@inheritdoc}
   */
  public function getContexts() {
    // Make sure all context objects are initialized.
    foreach ($this->getContextDefinitions() as $name => $definition) {
      $this->getContext($name);
    }
    return $this->context;
  }

  /**
   * {@inheritdoc}
   */
  public function getContext($name) {
    // Check for a valid context value.
    if (!isset($this->context[$name])) {
      $this->context[$name] = new Context($this->getContextDefinition($name));
    }
    return $this->context[$name];
  }

  /**
   * {@inheritdoc}
   */
  public function setContext($name, ContextInterface $context) {
    $this->context[$name] = $context;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextValues() {
    $values = [];
    foreach ($this->getContextDefinitions() as $name => $definition) {
      $values[$name] = isset($this->context[$name]) ? $this->context[$name]->getContextValue() : NULL;
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextValue($name) {
    return $this->getContext($name)->getContextValue();
  }

  /**
   * {@inheritdoc}
   */
  public function setContextValue($name, $value) {
    $this->context[$name] = new Context($this->getContextDefinition($name), $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function validateContexts() {
    $violations = new ConstraintViolationList();
    // @todo: Implement symfony validator API to let the validator traverse
    // and set property paths accordingly.

    foreach ($this->getContexts() as $context) {
      $violations->addAll($context->validate());
    }
    return $violations;
  }

}
