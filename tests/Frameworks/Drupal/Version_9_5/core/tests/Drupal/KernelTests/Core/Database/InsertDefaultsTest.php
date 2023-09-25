<?php

namespace Drupal\KernelTests\Core\Database;

use Drupal\Core\Database\Query\NoFieldsException;

/**
 * Tests the Insert query builder with default values.
 *
 * @group Database
 */
class InsertDefaultsTest extends DatabaseTestBase {

  /**
   * Tests that we can run a query that uses default values for everything.
   *
   * @see \database_test_schema()
   */
  public function testDefaultInsert() {
    $query = $this->connection->insert('test')->useDefaults(['job']);
    $id = $query->execute();
    $job = $this->connection->query('SELECT [job] FROM {test} WHERE [id] = :id', [':id' => $id])->fetchField();
    $this->assertSame('Undefined', $job, 'Default field value is set.');
  }

  /**
   * Tests that no action will be preformed if no fields are specified.
   */
  public function testDefaultEmptyInsert() {
    $num_records_before = (int) $this->connection->query('SELECT COUNT(*) FROM {test}')->fetchField();

    try {
      $this->connection->insert('test')->execute();
      // This is only executed if no exception has been thrown.
      $this->fail('Expected exception NoFieldsException has not been thrown.');
    }
    catch (NoFieldsException $e) {
      // Expected exception; just continue testing.
    }

    $num_records_after = (int) $this->connection->query('SELECT COUNT(*) FROM {test}')->fetchField();
    $this->assertSame($num_records_before, $num_records_after, 'Do nothing as no fields are specified.');
  }

  /**
   * Tests that we can insert fields with values and defaults in the same query.
   *
   * @see \database_test_schema()
   */
  public function testDefaultInsertWithFields() {
    $query = $this->connection->insert('test')
      ->fields(['name' => 'Bob'])
      ->useDefaults(['job']);
    $id = $query->execute();
    $job = $this->connection->query('SELECT [job] FROM {test} WHERE [id] = :id', [':id' => $id])->fetchField();
    $this->assertSame('Undefined', $job, 'Default field value is set.');
  }

}
