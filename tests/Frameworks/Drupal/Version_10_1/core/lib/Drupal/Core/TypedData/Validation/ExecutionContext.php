<?php

namespace Drupal\Core\TypedData\Validation;

use Drupal\Core\Validation\TranslatorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Mapping\MetadataInterface;
use Symfony\Component\Validator\Util\PropertyPath;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Defines an execution context class.
 *
 * We do not use the context provided by Symfony as it is marked internal, so
 * this class is pretty much the same, but has some code style changes as well
 * as exceptions for methods we don't support.
 */
class ExecutionContext implements ExecutionContextInterface {

  /**
   * @var \Symfony\Component\Validator\Validator\ValidatorInterface
   */
  protected $validator;

  /**
   * The root value of the validated object graph.
   *
   * @var mixed
   */
  protected $root;

  /**
   * @var \Drupal\Core\Validation\TranslatorInterface
   */
  protected $translator;

  /**
   * @var string
   */
  protected $translationDomain;

  /**
   * The violations generated in the current context.
   *
   * @var \Symfony\Component\Validator\ConstraintViolationList
   */
  protected $violations;

  /**
   * The currently validated value.
   *
   * @var mixed
   */
  protected $value;

  /**
   * The currently validated typed data object.
   *
   * @var \Drupal\Core\TypedData\TypedDataInterface
   */
  protected $data;

  /**
   * The property path leading to the current value.
   *
   * @var string
   */
  protected $propertyPath = '';

  /**
   * The current validation metadata.
   *
   * @var \Symfony\Component\Validator\Mapping\MetadataInterface|null
   */
  protected $metadata;

  /**
   * The currently validated group.
   *
   * @var string|null
   */
  protected $group;

  /**
   * The currently validated constraint.
   *
   * @var \Symfony\Component\Validator\Constraint|null
   */
  protected $constraint;

  /**
   * Stores which objects have been validated in which group.
   *
   * @var array
   */
  protected $validatedObjects = [];

  /**
   * Stores which class constraint has been validated for which object.
   *
   * @var array
   */
  protected $validatedConstraints = [];

  /**
   * Creates a new ExecutionContext.
   *
   * @param \Symfony\Component\Validator\Validator\ValidatorInterface $validator
   *   The validator.
   * @param mixed $root
   *   The root.
   * @param \Drupal\Core\Validation\TranslatorInterface $translator
   *   The translator.
   * @param string $translationDomain
   *   (optional) The translation domain.
   *
   * @internal Called by \Drupal\Core\TypedData\Validation\ExecutionContextFactory.
   *    Should not be used in user code.
   */
  public function __construct(ValidatorInterface $validator, $root, TranslatorInterface $translator, $translationDomain = NULL) {
    $this->validator = $validator;
    $this->root = $root;
    $this->translator = $translator;
    $this->translationDomain = $translationDomain;
    $this->violations = new ConstraintViolationList();
  }

  /**
   * {@inheritdoc}
   */
  public function setNode($value, $object, MetadataInterface $metadata = NULL, $propertyPath): void {
    $this->value = $value;
    $this->data = $object;
    $this->metadata = $metadata;
    $this->propertyPath = (string) $propertyPath;
  }

  /**
   * {@inheritdoc}
   */
  public function setGroup($group): void {
    $this->group = $group;
  }

  /**
   * {@inheritdoc}
   */
  public function setConstraint(Constraint $constraint): void {
    $this->constraint = $constraint;
  }

  /**
   * {@inheritdoc}
   *
   * phpcs:ignore Drupal.Commenting.FunctionComment.VoidReturn
   * @return void
   */
  public function addViolation($message, array $parameters = []) {
    $this->violations->add(new ConstraintViolation($this->translator->trans($message, $parameters, $this->translationDomain), $message, $parameters, $this->root, $this->propertyPath, $this->value, NULL, NULL, $this->constraint));
  }

  /**
   * {@inheritdoc}
   */
  public function buildViolation($message, array $parameters = []): ConstraintViolationBuilderInterface {
    return new ConstraintViolationBuilder($this->violations, $this->constraint, $message, $parameters, $this->root, $this->propertyPath, $this->value, $this->translator, $this->translationDomain);
  }

  /**
   * {@inheritdoc}
   */
  public function getViolations(): ConstraintViolationListInterface {
    return $this->violations;
  }

  /**
   * {@inheritdoc}
   */
  public function getValidator(): ValidatorInterface {
    return $this->validator;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoot(): mixed {
    return $this->root;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(): mixed {
    return $this->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getObject(): ?object {
    return $this->data;
  }

  /**
   * {@inheritdoc}
   */
  public function getMetadata(): ?MetadataInterface {
    return $this->metadata;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(): ?string {
    return Constraint::DEFAULT_GROUP;
  }

  /**
   * {@inheritdoc}
   */
  public function getClassName(): ?string {
    return get_class($this->data);
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyName(): ?string {
    return $this->data->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyPath($sub_path = ''): string {
    return PropertyPath::append($this->propertyPath, $sub_path);
  }

  /**
   * {@inheritdoc}
   */
  public function markConstraintAsValidated($cache_key, $constraint_hash): void {
    $this->validatedConstraints[$cache_key . ':' . $constraint_hash] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isConstraintValidated($cache_key, $constraint_hash): bool {
    return isset($this->validatedConstraints[$cache_key . ':' . $constraint_hash]);
  }

  /**
   * {@inheritdoc}
   */
  public function markGroupAsValidated($cache_key, $group_hash): void {
    $this->validatedObjects[$cache_key][$group_hash] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isGroupValidated($cache_key, $group_hash): bool {
    return isset($this->validatedObjects[$cache_key][$group_hash]);
  }

  /**
   * {@inheritdoc}
   */
  public function markObjectAsInitialized($cache_key): void {
    throw new \LogicException('\Symfony\Component\Validator\Context\ExecutionContextInterface::markObjectAsInitialized is unsupported.');
  }

  /**
   * {@inheritdoc}
   */
  public function isObjectInitialized($cache_key): bool {
    throw new \LogicException('\Symfony\Component\Validator\Context\ExecutionContextInterface::isObjectInitialized is unsupported.');
  }

}
