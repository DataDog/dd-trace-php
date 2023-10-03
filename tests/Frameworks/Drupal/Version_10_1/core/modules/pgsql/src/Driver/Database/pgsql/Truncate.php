<?php

namespace Drupal\pgsql\Driver\Database\pgsql;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

/**
 * PostgreSQL implementation of \Drupal\Core\Database\Query\Truncate.
 */
class Truncate extends QueryTruncate {

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $connection, string $table, array $options = []) {
    // @todo Remove the __construct in Drupal 11.
    // @see https://www.drupal.org/project/drupal/issues/3256524
    parent::__construct($connection, $table, $options);
    unset($this->queryOptions['return']);
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $this->connection->addSavepoint();
    try {
      $result = parent::execute();
    }
    catch (\Exception $e) {
      $this->connection->rollbackSavepoint();
      throw $e;
    }
    $this->connection->releaseSavepoint();

    return $result;
  }

}
