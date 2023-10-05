<?php

namespace Drupal\database_statement_monitoring_test;

use Drupal\Core\Database\Query\Condition;

/**
 * Trait for Connection classes that can store logged statements.
 */
trait LoggedStatementsTrait {

  /**
   * Logged statements.
   *
   * @var string[]
   */
  protected $loggedStatements;

  /**
   * {@inheritdoc}
   */
  public function query($query, array $args = [], $options = []) {
    // Log the query if it is a string, can receive statement objects e.g
    // in the pgsql driver. These are hard to log as the table name has already
    // been replaced.
    if (is_string($query)) {
      $stringified_args = array_map(function ($v) {
        return is_array($v) ? implode(',', $v) : $v;
      }, $args);
      $this->loggedStatements[] = str_replace(array_keys($stringified_args), array_values($stringified_args), $query);
    }
    return parent::query($query, $args, $options);
  }

  /**
   * Resets logged statements.
   *
   * @return $this
   */
  public function resetLoggedStatements() {
    $this->loggedStatements = [];
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDriverClass($class) {
    // Override because the base class uses reflection to determine namespace
    // based on object, which would break.
    $namespace = (new \ReflectionClass(get_parent_class($this)))->getNamespaceName();
    $driver_class = $namespace . '\\' . $class;
    if (class_exists($driver_class)) {
      return $driver_class;
    }
    elseif ($class == 'Condition') {
      return Condition::class;
    }
    return $class;
  }

  /**
   * Returns the executed queries.
   *
   * @return string[]
   */
  public function getLoggedStatements() {
    return $this->loggedStatements;
  }

}
