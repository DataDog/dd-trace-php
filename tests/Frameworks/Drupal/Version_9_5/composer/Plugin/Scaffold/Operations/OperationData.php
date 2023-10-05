<?php

namespace Drupal\Composer\Plugin\Scaffold\Operations;

/**
 * Holds parameter data for operation objects during operation creation only.
 *
 * @internal
 */
class OperationData {

  const MODE = 'mode';
  const PATH = 'path';
  const OVERWRITE = 'overwrite';
  const PREPEND = 'prepend';
  const APPEND = 'append';
  const DEFAULT = 'default';
  const FORCE_APPEND = 'force-append';

  /**
   * The parameter data.
   *
   * @var array
   */
  protected $data;

  /**
   * The destination path.
   *
   * @var string
   */
  protected $destination;

  /**
   * OperationData constructor.
   *
   * @param string $destination
   *   The destination path.
   * @param mixed $data
   *   The raw data array to wrap.
   */
  public function __construct($destination, $data) {
    $this->destination = $destination;
    $this->data = $this->normalizeScaffoldMetadata($destination, $data);
  }

  /**
   * Gets the destination path that this operation data is associated with.
   *
   * @return string
   *   The destination path for the scaffold result.
   */
  public function destination() {
    return $this->destination;
  }

  /**
   * Gets operation mode.
   *
   * @return string
   *   Operation mode.
   */
  public function mode() {
    return $this->data[self::MODE];
  }

  /**
   * Checks if path exists.
   *
   * @return bool
   *   Returns true if path exists
   */
  public function hasPath() {
    return isset($this->data[self::PATH]);
  }

  /**
   * Gets path.
   *
   * @return string
   *   The path.
   */
  public function path() {
    return $this->data[self::PATH];
  }

  /**
   * Determines overwrite.
   *
   * @return bool
   *   Returns true if overwrite mode was selected.
   */
  public function overwrite() {
    return !empty($this->data[self::OVERWRITE]);
  }

  /**
   * Determines whether 'force-append' has been set.
   *
   * @return bool
   *   Returns true if 'force-append' mode was selected.
   */
  public function forceAppend() {
    if ($this->hasDefault()) {
      return TRUE;
    }
    return !empty($this->data[self::FORCE_APPEND]);
  }

  /**
   * Checks if prepend path exists.
   *
   * @return bool
   *   Returns true if prepend exists.
   */
  public function hasPrepend() {
    return isset($this->data[self::PREPEND]);
  }

  /**
   * Gets prepend path.
   *
   * @return string
   *   Path to prepend data
   */
  public function prepend() {
    return $this->data[self::PREPEND];
  }

  /**
   * Checks if append path exists.
   *
   * @return bool
   *   Returns true if prepend exists.
   */
  public function hasAppend() {
    return isset($this->data[self::APPEND]);
  }

  /**
   * Gets append path.
   *
   * @return string
   *   Path to append data
   */
  public function append() {
    return $this->data[self::APPEND];
  }

  /**
   * Checks if default path exists.
   *
   * @return bool
   *   Returns true if there is default data available.
   */
  public function hasDefault() {
    return isset($this->data[self::DEFAULT]);
  }

  /**
   * Gets default path.
   *
   * @return string
   *   Path to default data
   */
  public function default() {
    return $this->data[self::DEFAULT];
  }

  /**
   * Normalizes metadata by converting literal values into arrays.
   *
   * Conversions performed include:
   *   - Boolean 'false' means "skip".
   *   - A string means "replace", with the string value becoming the path.
   *
   * @param string $destination
   *   The destination path for the scaffold file.
   * @param mixed $value
   *   The metadata for this operation object, which varies by operation type.
   *
   * @return array
   *   Normalized scaffold metadata with default values.
   */
  protected function normalizeScaffoldMetadata($destination, $value) {
    $defaultScaffoldMetadata = [
      self::MODE => ReplaceOp::ID,
      self::PREPEND => NULL,
      self::APPEND => NULL,
      self::DEFAULT => NULL,
      self::OVERWRITE => TRUE,
    ];

    return $this->convertScaffoldMetadata($destination, $value) + $defaultScaffoldMetadata;
  }

  /**
   * Performs the conversion-to-array step in normalizeScaffoldMetadata.
   *
   * @param string $destination
   *   The destination path for the scaffold file.
   * @param mixed $value
   *   The metadata for this operation object, which varies by operation type.
   *
   * @return array
   *   Normalized scaffold metadata.
   */
  protected function convertScaffoldMetadata($destination, $value) {
    if (is_bool($value)) {
      if (!$value) {
        return [self::MODE => SkipOp::ID];
      }
      throw new \RuntimeException("File mapping {$destination} cannot be given the value 'true'.");
    }
    if (empty($value)) {
      throw new \RuntimeException("File mapping {$destination} cannot be empty.");
    }
    if (is_string($value)) {
      $value = [self::PATH => $value];
    }
    // If there is no 'mode', but there is an 'append' or a 'prepend' path,
    // then the mode is 'append' (append + prepend).
    if (!isset($value[self::MODE]) && (isset($value[self::APPEND]) || isset($value[self::PREPEND]))) {
      $value[self::MODE] = AppendOp::ID;
    }
    return $value;
  }

}
