<?php

namespace Drupal\Tests\pgsql\Unit;

use Drupal\pgsql\Driver\Database\pgsql\Schema;
use Drupal\Tests\UnitTestCase;

// cSpell:ignore conname

/**
 * @coversDefaultClass \Drupal\pgsql\Driver\Database\pgsql\Schema
 * @group Database
 */
class SchemaTest extends UnitTestCase {

  /**
   * The PostgreSql DB connection.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\pgsql\Driver\Database\pgsql\Connection
   */
  protected $connection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->connection = $this->getMockBuilder('\Drupal\pgsql\Driver\Database\pgsql\Connection')
      ->disableOriginalConstructor()
      ->getMock();
  }

  /**
   * Tests whether the actual constraint name is correctly computed.
   *
   * @param string $table_name
   *   The table name the constrained column belongs to.
   * @param string $name
   *   The constraint name.
   * @param string $expected
   *   The expected computed constraint name.
   *
   * @covers ::constraintExists
   * @dataProvider providerComputedConstraintName
   */
  public function testComputedConstraintName($table_name, $name, $expected) {
    $max_identifier_length = 63;
    $schema = new Schema($this->connection);

    $statement = $this->createMock('\Drupal\Core\Database\StatementInterface');
    $statement->expects($this->any())
      ->method('fetchField')
      ->willReturn($max_identifier_length);

    $this->connection->expects($this->exactly(2))
      ->method('query')
      ->withConsecutive(
        [$this->anything()],
        ["SELECT 1 FROM pg_constraint WHERE conname = '$expected'"],
      )
      ->willReturnOnConsecutiveCalls(
        $statement,
        $this->createMock('\Drupal\Core\Database\StatementInterface'),
      );

    $schema->constraintExists($table_name, $name);
  }

  /**
   * Data provider for ::testComputedConstraintName().
   */
  public function providerComputedConstraintName() {
    return [
      ['user_field_data', 'pkey', 'user_field_data____pkey'],
      ['user_field_data', 'name__key', 'user_field_data__name__key'],
      ['user_field_data', 'a_very_very_very_very_super_long_field_name__key', 'drupal_WW_a8TlbZ3UQi20UTtRlJFaIeSa6FEtQS5h4NRA3UeU_key'],
    ];
  }

}
