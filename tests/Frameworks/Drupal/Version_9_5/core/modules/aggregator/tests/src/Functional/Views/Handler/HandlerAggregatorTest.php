<?php

namespace Drupal\Tests\aggregator\Functional\Views\Handler;

use Drupal\Tests\views\Functional\ViewTestBase;
use Drupal\views\Plugin\views\filter\NumericFilter;
use Drupal\views\ViewExecutable;
use Drupal\views\Plugin\views\HandlerBase;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\Entity\View;

/**
 * Tests instances of the aggregator related view handlers.
 *
 * @group aggregator
 * @group legacy
 */
class HandlerAggregatorTest extends ViewTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'aggregator',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests most of the handlers.
   */
  public function testHandlers() {
    $this->drupalCreateContentType(['type' => 'article']);

    $object_types = array_keys(ViewExecutable::getHandlerTypes());
    foreach ($this->container->get('views.views_data')->getAll() as $base_table => $info) {
      if (!isset($info['table']['base'])) {
        continue;
      }

      $view = View::create(['base_table' => $base_table]);
      $view = $view->getExecutable();

      // @todo The groupwise relationship is currently broken.
      $exclude[] = 'users_field_data:uid_representative';

      // Go through all fields and there through all handler types.
      foreach ($info as $field => $field_info) {
        // Table is a reserved key for the meta-information.
        if ($field !== 'table' && !in_array("$base_table:$field", $exclude)) {
          $item = [
            'table' => $base_table,
            'field' => $field,
          ];
          foreach ($object_types as $type) {
            if (isset($field_info[$type]['id'])) {
              $options = [];
              if ($type === 'filter') {
                $handler = $this->container->get("plugin.manager.views.$type")->getHandler($item);
                // Set the value to use for the filter based on the filter type.
                if ($handler instanceof InOperator) {
                  $options['value'] = [1];
                }
                elseif ($handler instanceof NumericFilter) {
                  $options['value'] = ['value' => 1];
                }
                else {
                  $options['value'] = 1;
                }
              }
              $view->addHandler('default', $type, $base_table, $field, $options);
            }
          }
        }
      }

      // Go through each step individually to see whether some parts are
      // failing.
      $view->build();
      $view->preExecute();
      $view->execute();
      $view->render();

      // Make sure all handlers extend the HandlerBase.
      foreach ($object_types as $type) {
        if (isset($view->{$type})) {
          foreach ($view->{$type} as $handler) {
            $this->assertInstanceOf(HandlerBase::class, $handler);
          }
        }
      }
    }
  }

}
