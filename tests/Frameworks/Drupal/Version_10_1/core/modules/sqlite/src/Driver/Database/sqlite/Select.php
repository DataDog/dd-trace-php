<?php

namespace Drupal\sqlite\Driver\Database\sqlite;

use Drupal\Core\Database\Query\Select as QuerySelect;

/**
 * SQLite implementation of \Drupal\Core\Database\Query\Select.
 */
class Select extends QuerySelect {

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $connection, $table, $alias = NULL, array $options = []) {
    // @todo Remove the __construct in Drupal 11.
    // @see https://www.drupal.org/project/drupal/issues/3256524
    parent::__construct($connection, $table, $alias, $options);
    unset($this->queryOptions['return']);
  }

  public function forUpdate($set = TRUE) {
    // SQLite does not support FOR UPDATE so nothing to do.
    return $this;
  }

}
