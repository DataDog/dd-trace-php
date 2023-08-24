<?php

namespace Drupal\KernelTests\Core\Database;

/**
 * Tests SQL syntax interpretation.
 *
 * In order to ensure consistent SQL handling throughout Drupal
 * across multiple kinds of database systems, we test that the
 * database system interprets SQL syntax in an expected fashion.
 *
 * @group Database
 */
class BasicSyntaxTest extends DatabaseTestBase {

  /**
   * Tests string concatenation.
   */
  public function testConcatLiterals() {
    $result = $this->connection->query('SELECT CONCAT(:a1, CONCAT(:a2, CONCAT(:a3, CONCAT(:a4, :a5))))', [
      ':a1' => 'This',
      ':a2' => ' ',
      ':a3' => 'is',
      ':a4' => ' a ',
      ':a5' => 'test.',
    ]);
    $this->assertSame('This is a test.', $result->fetchField(), 'Basic CONCAT works.');
  }

  /**
   * Tests string concatenation with field values.
   *
   * We use 'job' and 'age' fields from the {test} table. Using the 'name' field
   * for concatenation causes issues with custom or contrib database drivers,
   * since its type 'varchar_ascii' may lead to using field-level collations not
   * compatible with the other fields.
   */
  public function testConcatFields() {
    $result = $this->connection->query(
      'SELECT CONCAT(:a1, CONCAT([job], CONCAT(:a2, CONCAT([age], :a3)))) FROM {test} WHERE [age] = :age', [
        ':a1' => 'The age of ',
        ':a2' => ' is ',
        ':a3' => '.',
        ':age' => 25,
      ]
    );
    $this->assertSame('The age of Singer is 25.', $result->fetchField(), 'Field CONCAT works.');
  }

  /**
   * Tests string concatenation with separator.
   */
  public function testConcatWsLiterals() {
    $result = $this->connection->query("SELECT CONCAT_WS(', ', :a1, NULL, :a2, :a3, :a4)", [
      ':a1' => 'Hello',
      ':a2' => NULL,
      ':a3' => '',
      ':a4' => 'world.',
    ]);
    $this->assertSame('Hello, , world.', $result->fetchField());
  }

  /**
   * Tests string concatenation with separator, with field values.
   */
  public function testConcatWsFields() {
    $result = $this->connection->query("SELECT CONCAT_WS('-', :a1, [name], :a2, [age]) FROM {test} WHERE [age] = :age", [
      ':a1' => 'name',
      ':a2' => 'age',
      ':age' => 25,
    ]);
    $this->assertSame('name-John-age-25', $result->fetchField());
  }

  /**
   * Tests escaping of LIKE wildcards.
   */
  public function testLikeEscape() {
    $this->connection->insert('test')
      ->fields([
        'name' => 'Ring_',
      ])
      ->execute();

    // Match both "Ringo" and "Ring_".
    $num_matches = $this->connection->select('test', 't')
      ->condition('name', 'Ring_', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('2', $num_matches, 'Found 2 records.');
    // Match only "Ring_" using a LIKE expression with no wildcards.
    $num_matches = $this->connection->select('test', 't')
      ->condition('name', $this->connection->escapeLike('Ring_'), 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', $num_matches, 'Found 1 record.');
  }

  /**
   * Tests a LIKE query containing a backslash.
   */
  public function testLikeBackslash() {
    $this->connection->insert('test')
      ->fields(['name'])
      ->values([
        'name' => 'abcde\f',
      ])
      ->values([
        'name' => 'abc%\_',
      ])
      ->execute();

    // Match both rows using a LIKE expression with two wildcards and a verbatim
    // backslash.
    $num_matches = $this->connection->select('test', 't')
      ->condition('name', 'abc%\\\\_', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('2', $num_matches, 'Found 2 records.');
    // Match only the former using a LIKE expression with no wildcards.
    $num_matches = $this->connection->select('test', 't')
      ->condition('name', $this->connection->escapeLike('abc%\_'), 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('1', $num_matches, 'Found 1 record.');
  }

  /**
   * Tests \Drupal\Core\Database\Connection::getFullQualifiedTableName().
   */
  public function testGetFullQualifiedTableName() {
    $database = \Drupal::database();
    $num_matches = $database->select($database->getFullQualifiedTableName('test'), 't')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertSame('4', $num_matches, 'Found 4 records.');
  }

  /**
   * Tests allowing square brackets in queries.
   *
   * @see \Drupal\Core\Database\Connection::prepareQuery()
   */
  public function testAllowSquareBrackets() {
    $this->connection->insert('test')
      ->fields(['name'])
      ->values([
        'name' => '[square]',
      ])
      ->execute();

    // Note that this is a very bad example query because arguments should be
    // passed in via the $args parameter.
    $result = $this->connection->query("select name from {test} where name = '[square]'", [], ['allow_square_brackets' => TRUE]);
    $this->assertSame('[square]', $result->fetchField());

    // Test that allow_square_brackets has no effect on arguments.
    $result = $this->connection->query("select [name] from {test} where [name] = :value", [':value' => '[square]']);
    $this->assertSame('[square]', $result->fetchField());
    $result = $this->connection->query("select name from {test} where name = :value", [':value' => '[square]'], ['allow_square_brackets' => TRUE]);
    $this->assertSame('[square]', $result->fetchField());

    // Test square brackets using the query builder.
    $result = $this->connection->select('test')->fields('test', ['name'])->condition('name', '[square]')->execute();
    $this->assertSame('[square]', $result->fetchField());
  }

}
