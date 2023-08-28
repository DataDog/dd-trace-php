<?php

namespace Drupal\Tests\mysql\Kernel\mysql;

use Drupal\KernelTests\Core\Database\DriverSpecificDatabaseTestBase;

/**
 * Tests compatibility of the MySQL driver with various sql_mode options.
 *
 * @group Database
 */
class SqlModeTest extends DriverSpecificDatabaseTestBase {

  /**
   * Tests quoting identifiers in queries.
   */
  public function testQuotingIdentifiers(): void {
    // Use SQL-reserved words for both the table and column names.
    $query = $this->connection->query('SELECT [update] FROM {select}');
    $this->assertEquals('Update value 1', $query->fetchObject()->update);
    $this->assertStringContainsString('SELECT `update` FROM `', $query->getQueryString());
  }

  /**
   * {@inheritdoc}
   */
  protected function getDatabaseConnectionInfo() {
    $info = parent::getDatabaseConnectionInfo();

    // This runs during setUp(), so is not yet skipped for non MySQL databases.
    // We defer skipping the test to later in setUp(), so that that can be
    // based on databaseType() rather than 'driver', but here all we have to go
    // on is 'driver'.
    if ($info['default']['driver'] === 'mysql') {
      $info['default']['init_commands']['sql_mode'] = "SET sql_mode = ''";
    }

    return $info;
  }

}
