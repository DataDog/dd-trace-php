<?php

namespace Drupal\KernelTests\Core\Database;

use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\InvalidMergeQueryException;

/**
 * Tests the MERGE query builder.
 *
 * @group Database
 */
class MergeTest extends DatabaseTestBase {

  /**
   * Confirms that we can merge-insert a record successfully.
   */
  public function testMergeInsert() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $result = $this->connection->merge('test_people')
      ->key('job', 'Presenter')
      ->fields([
        'age' => 31,
        'name' => 'Tiffany',
      ])
      ->execute();

    $this->assertEquals(Merge::STATUS_INSERT, $result, 'Insert status returned.');

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before + 1, $num_records_after, 'Merge inserted properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Presenter'])->fetch();
    $this->assertEquals('Tiffany', $person->name, 'Name set correctly.');
    $this->assertEquals(31, $person->age, 'Age set correctly.');
    $this->assertEquals('Presenter', $person->job, 'Job set correctly.');
  }

  /**
   * Confirms that we can merge-update a record successfully.
   */
  public function testMergeUpdate() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $result = $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->fields([
        'age' => 31,
        'name' => 'Tiffany',
      ])
      ->execute();

    $this->assertEquals(Merge::STATUS_UPDATE, $result, 'Update status returned.');

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge updated properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Tiffany', $person->name, 'Name set correctly.');
    $this->assertEquals(31, $person->age, 'Age set correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job set correctly.');
  }

  /**
   * Confirms that we can merge-update a record successfully.
   *
   * This test varies from the previous test because it manually defines which
   * fields are inserted, and which fields are updated.
   */
  public function testMergeUpdateExcept() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->insertFields(['age' => 31])
      ->updateFields(['name' => 'Tiffany'])
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge updated properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Tiffany', $person->name, 'Name set correctly.');
    $this->assertEquals(30, $person->age, 'Age skipped correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job set correctly.');
  }

  /**
   * Confirms that we can merge-update a record, with alternate replacement.
   */
  public function testMergeUpdateExplicit() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->insertFields([
        'age' => 31,
        'name' => 'Tiffany',
      ])
      ->updateFields([
        'name' => 'Joe',
      ])
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge updated properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Joe', $person->name, 'Name set correctly.');
    $this->assertEquals(30, $person->age, 'Age skipped correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job set correctly.');
  }

  /**
   * Confirms that we can merge-update a record successfully, with expressions.
   */
  public function testMergeUpdateExpression() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $age_before = $this->connection->query('SELECT [age] FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetchField();

    // This is a very contrived example, as I have no idea why you'd want to
    // change age this way, but that's beside the point.
    // Note that we are also double-setting age here, once as a literal and
    // once as an expression. This test will only pass if the expression wins,
    // which is what is supposed to happen.
    $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->fields(['name' => 'Tiffany'])
      ->insertFields(['age' => 31])
      ->expression('age', '[age] + :age', [':age' => 4])
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge updated properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Tiffany', $person->name, 'Name set correctly.');
    $this->assertEquals($age_before + 4, $person->age, 'Age updated correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job set correctly.');
  }

  /**
   * Tests that we can merge-insert without any update fields.
   */
  public function testMergeInsertWithoutUpdate() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $this->connection->merge('test_people')
      ->key('job', 'Presenter')
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before + 1, $num_records_after, 'Merge inserted properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Presenter'])->fetch();
    $this->assertEquals('', $person->name, 'Name set correctly.');
    $this->assertEquals(0, $person->age, 'Age set correctly.');
    $this->assertEquals('Presenter', $person->job, 'Job set correctly.');
  }

  /**
   * Confirms that we can merge-update without any update fields.
   */
  public function testMergeUpdateWithoutUpdate() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();

    $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge skipped properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Meredith', $person->name, 'Name skipped correctly.');
    $this->assertEquals(30, $person->age, 'Age skipped correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job skipped correctly.');

    $this->connection->merge('test_people')
      ->key('job', 'Speaker')
      ->insertFields(['age' => 31])
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test_people}')->fetchField();
    $this->assertEquals($num_records_before, $num_records_after, 'Merge skipped properly.');

    $person = $this->connection->query('SELECT * FROM {test_people} WHERE [job] = :job', [':job' => 'Speaker'])->fetch();
    $this->assertEquals('Meredith', $person->name, 'Name skipped correctly.');
    $this->assertEquals(30, $person->age, 'Age skipped correctly.');
    $this->assertEquals('Speaker', $person->job, 'Job skipped correctly.');
  }

  /**
   * Tests that an invalid merge query throws an exception.
   */
  public function testInvalidMerge() {
    $this->expectException(InvalidMergeQueryException::class);
    // This merge will fail because there is no key field specified.
    $this->connection
      ->merge('test_people')
      ->fields(['age' => 31, 'name' => 'Tiffany'])
      ->execute();
  }

  /**
   * Tests deprecation of the 'throw_exception' option.
   *
   * @group legacy
   */
  public function testLegacyThrowExceptionOption(): void {
    $this->expectDeprecation("Passing a 'throw_exception' option to %AMerge::execute is deprecated in drupal:9.2.0 and is removed in drupal:10.0.0. Always catch exceptions. See https://www.drupal.org/node/3201187");
    // This merge will fail because there is no key field specified.
    $this->assertNull($this->connection
      ->merge('test_people', ['throw_exception' => FALSE])
      ->fields(['age' => 31, 'name' => 'Tiffany'])
      ->execute()
    );
  }

  /**
   * Tests that we can merge-insert with reserved keywords.
   */
  public function testMergeWithReservedWords() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {select}')->fetchField();

    $this->connection->merge('select')
      ->key('id', 2)
      ->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {select}')->fetchField();
    $this->assertEquals($num_records_before + 1, $num_records_after, 'Merge inserted properly.');

    $person = $this->connection->query('SELECT * FROM {select} WHERE [id] = :id', [':id' => 2])->fetch();
    $this->assertEquals('', $person->update);
    $this->assertEquals('2', $person->id);
  }

}
