<?php

namespace Drupal\Tests\views\Functional;

use Behat\Mink\Exception\ElementNotFoundException;
use Drupal\Core\Database\Database;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Tests\ViewResultAssertionTrait;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\ViewExecutable;

/**
 * Defines a base class for Views testing in the full web test environment.
 *
 * Use this base test class if you need to emulate a full Drupal installation.
 * When possible, ViewsKernelTestBase should be used instead. Both base classes
 * include the same methods.
 *
 * @see \Drupal\Tests\views\Kernel\ViewsKernelTestBase
 */
abstract class ViewTestBase extends BrowserTestBase {

  use ViewResultAssertionTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['views', 'views_test_config'];

  /**
   * Sets up the test.
   *
   * @param bool $import_test_views
   *   Should the views specified on the test class be imported. If you need
   *   to setup some additional stuff, like fields, you need to call false and
   *   then call createTestViews for your own.
   * @param array $modules
   *   The module directories to look in for test views.
   */
  protected function setUp($import_test_views = TRUE, $modules = ['views_test_config']): void {
    parent::setUp();
    if ($import_test_views) {
      ViewTestData::createTestViews(static::class, $modules);
    }
  }

  /**
   * Sets up the views_test_data.module.
   *
   * Because the schema of views_test_data.module is dependent on the test
   * using it, it cannot be enabled normally.
   */
  protected function enableViewsTestModule() {
    // Define the schema and views data variable before enabling the test module.
    \Drupal::state()->set('views_test_data_schema', $this->schemaDefinition());
    \Drupal::state()->set('views_test_data_views_data', $this->viewsData());

    \Drupal::service('module_installer')->install(['views_test_data']);
    $this->resetAll();
    $this->rebuildContainer();
    $this->container->get('module_handler')->reload();

    // Load the test dataset.
    $data_set = $this->dataSet();
    $query = Database::getConnection()->insert('views_test_data')
      ->fields(array_keys($data_set[0]));
    foreach ($data_set as $record) {
      $query->values($record);
    }
    $query->execute();
  }

  /**
   * Orders a nested array containing a result set based on a given column.
   *
   * @param array $result_set
   *   An array of rows from a result set, with each row as an associative
   *   array keyed by column name.
   * @param string $column
   *   The column name by which to sort the result set.
   * @param bool $reverse
   *   (optional) Boolean indicating whether to sort the result set in reverse
   *   order. Defaults to FALSE.
   *
   * @return array
   *   The sorted result set.
   */
  protected function orderResultSet($result_set, $column, $reverse = FALSE) {
    $order = $reverse ? -1 : 1;
    usort($result_set, function ($a, $b) use ($column, $order) {
      return $order * ($a[$column] <=> $b[$column]);
    });
    return $result_set;
  }

  /**
   * Asserts the existence of a button with a certain ID and label.
   *
   * @param string $id
   *   The HTML ID of the button
   * @param string $expected_label
   *   The expected label for the button.
   * @param string $message
   *   (optional) A custom message to display with the assertion. If no custom
   *   message is provided, the message will indicate the button label.
   *
   * @throws \Behat\Mink\Exception\ElementNotFoundException
   */
  protected function helperButtonHasLabel($id, $expected_label, $message = 'Label has the expected value: %label.') {
    $xpath = $this->assertSession()->buildXPathQuery('//button[@id=:value]|//input[@id=:value]', [':value' => $id]);
    $field = $this->getSession()->getPage()->find('xpath', $xpath);

    if (empty($field)) {
      throw new ElementNotFoundException($this->getSession()->getDriver(), 'form field', 'id', $field);
    }

    $this->assertEquals($expected_label, $field->getValue());
  }

  /**
   * Executes a view.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view object.
   * @param array $args
   *   (optional) An array of the view arguments to use for the view.
   */
  protected function executeView(ViewExecutable $view, $args = []) {
    // A view does not really work outside of a request scope, due to many
    // dependencies like the current user.
    $view->setDisplay();
    $view->preExecute($args);
    $view->execute();
  }

  /**
   * Returns the schema definition.
   *
   * @internal
   */
  protected function schemaDefinition() {
    return ViewTestData::schemaDefinition();
  }

  /**
   * Returns the views data definition.
   */
  protected function viewsData() {
    return ViewTestData::viewsData();
  }

  /**
   * Returns a very simple test dataset.
   */
  protected function dataSet() {
    return ViewTestData::dataSet();
  }

}
