<?php

namespace Drupal\Tests\views\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\comment\Tests\CommentTestTrait;
use Drupal\Component\Utility\Xss;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;
use Drupal\views\ViewExecutableFactory;
use Drupal\views\DisplayPluginCollection;
use Drupal\views\Plugin\views\display\DefaultDisplay;
use Drupal\views\Plugin\views\display\Page;
use Drupal\views\Plugin\views\style\DefaultStyle;
use Drupal\views\Plugin\views\style\Grid;
use Drupal\views\Plugin\views\row\Fields;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\Plugin\views\pager\PagerPluginBase;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views_test_data\Plugin\views\display\DisplayTest;
use PHPUnit\Framework\Error\Warning;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the ViewExecutable class.
 *
 * @group views
 * @see \Drupal\views\ViewExecutable
 */
class ViewExecutableTest extends ViewsKernelTestBase {

  use CommentTestTrait;

  public static $modules = [
    'system',
    'node',
    'comment',
    'user',
    'filter',
    'field',
    'text',
  ];

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_destroy', 'test_executable_displays', 'test_argument_dependency'];

  /**
   * Properties that should be stored in the configuration.
   *
   * @var array
   */
  protected $configProperties = [
    'disabled',
    'name',
    'description',
    'tag',
    'base_table',
    'label',
    'core',
    'display',
  ];

  /**
   * Properties that should be stored in the executable.
   *
   * @var array
   */
  protected $executableProperties = [
    'storage',
    'built',
    'executed',
    'args',
    'build_info',
    'result',
    'attachment_before',
    'attachment_after',
    'exposed_data',
    'exposed_raw_input',
    'old_view',
    'parent_views',
  ];

  protected function setUpFixtures() {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('comment');
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['system', 'field', 'node', 'comment']);

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    $this->addDefaultCommentField('node', 'page');
    parent::setUpFixtures();

    $this->installConfig(['filter']);
  }

  /**
   * Tests the views.executable container service.
   */
  public function testFactoryService() {
    $factory = $this->container->get('views.executable');
    $this->assertInstanceOf(ViewExecutableFactory::class, $factory);
    $view = View::load('test_executable_displays');
    $this->assertInstanceOf(ViewExecutable::class, $factory->get($view));
  }

  /**
   * Tests the initDisplay() and initHandlers() methods.
   */
  public function testInitMethods() {
    $view = Views::getView('test_destroy');
    $view->initDisplay();

    $this->assertInstanceOf(DefaultDisplay::class, $view->display_handler);
    $this->assertInstanceOf(DefaultDisplay::class, $view->displayHandlers->get('default'));

    $view->destroy();
    $view->initHandlers();

    // Check for all handler types.
    $handler_types = array_keys(ViewExecutable::getHandlerTypes());
    foreach ($handler_types as $type) {
      // The views_test integration doesn't have relationships.
      if ($type == 'relationship') {
        continue;
      }
      $this->assertGreaterThan(0, count($view->$type), new FormattableMarkup('Make sure a %type instance got instantiated.', ['%type' => $type]));
    }

    // initHandlers() should create display handlers automatically as well.
    $this->assertInstanceOf(DefaultDisplay::class, $view->display_handler);
    $this->assertInstanceOf(DefaultDisplay::class, $view->displayHandlers->get('default'));

    $view_hash = spl_object_hash($view);
    $display_hash = spl_object_hash($view->display_handler);

    // Test the initStyle() method.
    $view->initStyle();
    $this->assertInstanceOf(DefaultStyle::class, $view->style_plugin);
    // Test the plugin has been invited and view have references to the view and
    // display handler.
    $this->assertEqual(spl_object_hash($view->style_plugin->view), $view_hash);
    $this->assertEqual(spl_object_hash($view->style_plugin->displayHandler), $display_hash);

    // Test the initQuery method().
    $view->initQuery();
    $this->assertInstanceOf(Sql::class, $view->query);
    $this->assertEqual(spl_object_hash($view->query->view), $view_hash);
    $this->assertEqual(spl_object_hash($view->query->displayHandler), $display_hash);

    $view->destroy();

    // Test the plugin  get methods.
    $display_plugin = $view->getDisplay();
    $this->assertInstanceOf(DefaultDisplay::class, $display_plugin);
    $this->assertInstanceOf(DefaultDisplay::class, $view->display_handler);
    $this->assertIdentical($display_plugin, $view->getDisplay(), 'The same display plugin instance was returned.');

    $style_plugin = $view->getStyle();
    $this->assertInstanceOf(DefaultStyle::class, $style_plugin);
    $this->assertInstanceOf(DefaultStyle::class, $view->style_plugin);
    $this->assertIdentical($style_plugin, $view->getStyle(), 'The same style plugin instance was returned.');

    $pager_plugin = $view->getPager();
    $this->assertInstanceOf(PagerPluginBase::class, $pager_plugin);
    $this->assertInstanceOf(PagerPluginBase::class, $view->pager);
    $this->assertIdentical($pager_plugin, $view->getPager(), 'The same pager plugin instance was returned.');

    $query_plugin = $view->getQuery();
    $this->assertInstanceOf(QueryPluginBase::class, $query_plugin);
    $this->assertInstanceOf(QueryPluginBase::class, $view->query);
    $this->assertIdentical($query_plugin, $view->getQuery(), 'The same query plugin instance was returned.');
  }

  /**
   * Tests the generation of the executable object.
   */
  public function testConstructing() {
    Views::getView('test_destroy');
  }

  /**
   * Tests the accessing of values on the object.
   */
  public function testProperties() {
    $view = Views::getView('test_destroy');
    foreach ($this->executableProperties as $property) {
      $this->assertTrue(isset($view->{$property}));
    }

    // Per default exposed input should fall back to an empty array.
    $this->assertEqual($view->getExposedInput(), []);
  }

  public function testSetDisplayWithInvalidDisplay() {
    $view = Views::getView('test_executable_displays');
    $view->initDisplay();

    // Error is triggered while calling the wrong display.
    try {
      $view->setDisplay('invalid');
      $this->fail('Expected error, when setDisplay() called with invalid display ID');
    }
    catch (Warning $e) {
      $this->assertEquals('setDisplay() called with invalid display ID "invalid".', $e->getMessage());
    }

    $this->assertEqual($view->current_display, 'default', 'If setDisplay is called with an invalid display id the default display should be used.');
    $this->assertEqual(spl_object_hash($view->display_handler), spl_object_hash($view->displayHandlers->get('default')));
  }

  /**
   * Tests the display related methods and properties.
   */
  public function testDisplays() {
    $view = Views::getView('test_executable_displays');

    // Tests Drupal\views\ViewExecutable::initDisplay().
    $view->initDisplay();
    $this->assertInstanceOf(DisplayPluginCollection::class, $view->displayHandlers);
    // Tests the classes of the instances.
    $this->assertInstanceOf(DefaultDisplay::class, $view->displayHandlers->get('default'));
    $this->assertInstanceOf(Page::class, $view->displayHandlers->get('page_1'));
    $this->assertInstanceOf(Page::class, $view->displayHandlers->get('page_2'));

    // After initializing the default display is the current used display.
    $this->assertEqual($view->current_display, 'default');
    $this->assertEqual(spl_object_hash($view->display_handler), spl_object_hash($view->displayHandlers->get('default')));

    // All handlers should have a reference to the default display.
    $this->assertEqual(spl_object_hash($view->displayHandlers->get('page_1')->default_display), spl_object_hash($view->displayHandlers->get('default')));
    $this->assertEqual(spl_object_hash($view->displayHandlers->get('page_2')->default_display), spl_object_hash($view->displayHandlers->get('default')));

    // Tests Drupal\views\ViewExecutable::setDisplay().
    $view->setDisplay();
    $this->assertEqual($view->current_display, 'default', 'If setDisplay is called with no parameter the default display should be used.');
    $this->assertEqual(spl_object_hash($view->display_handler), spl_object_hash($view->displayHandlers->get('default')));

    // Set two different valid displays.
    $view->setDisplay('page_1');
    $this->assertEqual($view->current_display, 'page_1', 'If setDisplay is called with a valid display id the appropriate display should be used.');
    $this->assertEqual(spl_object_hash($view->display_handler), spl_object_hash($view->displayHandlers->get('page_1')));

    $view->setDisplay('page_2');
    $this->assertEqual($view->current_display, 'page_2', 'If setDisplay is called with a valid display id the appropriate display should be used.');
    $this->assertEqual(spl_object_hash($view->display_handler), spl_object_hash($view->displayHandlers->get('page_2')));

    // Destroy the view, so we can start again and test an invalid display.
    $view->destroy();

    // Test the style and row plugins are replaced correctly when setting the
    // display.
    $view->setDisplay('page_1');
    $view->initStyle();
    $this->assertInstanceOf(DefaultStyle::class, $view->style_plugin);
    $this->assertInstanceOf(Fields::class, $view->rowPlugin);

    $view->setDisplay('page_2');
    $view->initStyle();
    $this->assertInstanceOf(Grid::class, $view->style_plugin);
    // @todo Change this rowPlugin type too.
    $this->assertInstanceOf(Fields::class, $view->rowPlugin);

    // Test the newDisplay() method.
    $view = $this->container->get('entity_type.manager')->getStorage('view')->create(['id' => 'test_executable_displays']);
    $executable = $view->getExecutable();

    $executable->newDisplay('page');
    $executable->newDisplay('page');
    $executable->newDisplay('display_test');

    $this->assertInstanceOf(DefaultDisplay::class, $executable->displayHandlers->get('default'));
    $this->assertFalse(isset($executable->displayHandlers->get('default')->default_display));
    $this->assertInstanceOf(Page::class, $executable->displayHandlers->get('page_1'));
    $this->assertInstanceOf(DefaultDisplay::class, $executable->displayHandlers->get('page_1')->default_display);
    $this->assertInstanceOf(Page::class, $executable->displayHandlers->get('page_2'));
    $this->assertInstanceOf(DefaultDisplay::class, $executable->displayHandlers->get('page_2')->default_display);
    $this->assertInstanceOf(DisplayTest::class, $executable->displayHandlers->get('display_test_1'));
    $this->assertInstanceOf(DefaultDisplay::class, $executable->displayHandlers->get('display_test_1')->default_display);
  }

  /**
   * Tests the setting/getting of properties.
   */
  public function testPropertyMethods() {
    $view = Views::getView('test_executable_displays');

    // Test the setAjaxEnabled() method.
    $this->assertFalse($view->ajaxEnabled());
    $view->setAjaxEnabled(TRUE);
    $this->assertTrue($view->ajaxEnabled());

    $view->setDisplay();
    // There should be no pager set initially.
    $this->assertNull($view->usePager());

    // Add a pager, initialize, and test.
    $view->displayHandlers->get('default')->overrideOption('pager', [
      'type' => 'full',
      'options' => ['items_per_page' => 10],
    ]);
    $view->initPager();
    $this->assertTrue($view->usePager());

    // Test setting and getting the offset.
    $rand = rand();
    $view->setOffset($rand);
    $this->assertEqual($view->getOffset(), $rand);

    // Test the getBaseTable() method.
    $expected = [
      'views_test_data' => TRUE,
      '#global' => TRUE,
    ];
    $this->assertIdentical($view->getBaseTables(), $expected);

    // Test response methods.
    $this->assertInstanceOf(Response::class, $view->getResponse());
    $new_response = new Response();
    $view->setResponse($new_response);
    $this->assertIdentical(spl_object_hash($view->getResponse()), spl_object_hash($new_response), 'New response object correctly set.');

    // Test the getPath() method.
    $path = $this->randomMachineName();
    $view->displayHandlers->get('page_1')->overrideOption('path', $path);
    $view->setDisplay('page_1');
    $this->assertEqual($view->getPath(), $path);
    // Test the override_path property override.
    $override_path = $this->randomMachineName();
    $view->override_path = $override_path;
    $this->assertEqual($view->getPath(), $override_path);

    // Test the title methods.
    $title = $this->randomString();
    $view->setTitle($title);
    $this->assertEqual($view->getTitle(), Xss::filterAdmin($title));
  }

  /**
   * Tests the deconstructor to be sure that necessary objects are removed.
   */
  public function testDestroy() {
    $view = Views::getView('test_destroy');

    $view->preview();
    $view->destroy();

    $this->assertViewDestroy($view);
  }

  /**
   * Asserts that expected view properties have been unset by destroy().
   *
   * @param \Drupal\views\ViewExecutable $view
   */
  protected function assertViewDestroy($view) {
    $reflection = new \ReflectionClass($view);
    $defaults = $reflection->getDefaultProperties();
    // The storage and user should remain.
    unset(
      $defaults['storage'],
      $defaults['user'],
      $defaults['request'],
      $defaults['routeProvider'],
      $defaults['viewsData']
    );

    foreach ($defaults as $property => $default) {
      $this->assertIdentical($this->getProtectedProperty($view, $property), $default);
    }
  }

  /**
   * Returns a protected property from a class instance.
   *
   * @param object $instance
   *   The class instance to return the property from.
   * @param string $property
   *   The name of the property to return.
   *
   * @return mixed
   *   The instance property value.
   */
  protected function getProtectedProperty($instance, $property) {
    $reflection = new \ReflectionProperty($instance, $property);
    $reflection->setAccessible(TRUE);
    return $reflection->getValue($instance);
  }

  /**
   * Tests ViewExecutable::getHandlerTypes().
   */
  public function testGetHandlerTypes() {
    $types = ViewExecutable::getHandlerTypes();
    foreach (['field', 'filter', 'argument', 'sort', 'header', 'footer', 'empty'] as $type) {
      $this->assertTrue(isset($types[$type]));
      // @todo The key on the display should be footers, headers and empties
      //   or something similar instead of the singular, but so long check for
      //   this special case.
      if (isset($types[$type]['type']) && $types[$type]['type'] == 'area') {
        $this->assertEqual($types[$type]['plural'], $type);
      }
      else {
        $this->assertEqual($types[$type]['plural'], $type . 's');
      }
    }
  }

  /**
   * Tests ViewExecutable::getHandlers().
   */
  public function testGetHandlers() {
    $view = Views::getView('test_executable_displays');
    $view->setDisplay('page_1');

    $view->getHandlers('field', 'page_2');

    // getHandlers() shouldn't change the active display.
    $this->assertEqual('page_1', $view->current_display, "The display shouldn't change after getHandlers()");
  }

  /**
   * Tests the validation of display handlers.
   */
  public function testValidate() {
    $view = Views::getView('test_executable_displays');
    $view->setDisplay('page_1');

    $validate = $view->validate();

    // Validating a view shouldn't change the active display.
    $this->assertEqual('page_1', $view->current_display, "The display should be constant while validating");

    $count = 0;
    foreach ($view->displayHandlers as $id => $display) {
      $match = function ($value) use ($display) {
        return strpos($value, $display->display['display_title']) !== FALSE;
      };
      $this->assertNotEmpty(array_filter($validate[$id], $match), new FormattableMarkup('Error message found for @id display', ['@id' => $id]));
      $count++;
    }

    $this->assertEqual(count($view->displayHandlers), $count, 'Error messages from all handlers merged.');

    // Test that a deleted display is not included.
    $display = &$view->storage->getDisplay('default');
    $display['deleted'] = TRUE;
    $validate_deleted = $view->validate();

    $this->assertNotIdentical($validate, $validate_deleted, 'Master display has not been validated.');
  }

  /**
   * Tests that nested loops of the display handlers won't break validation.
   */
  public function testValidateNestedLoops() {
    $view = View::create(['id' => 'test_validate_nested_loops']);
    $executable = $view->getExecutable();

    $executable->newDisplay('display_test');
    $executable->newDisplay('display_test');
    $errors = $executable->validate();
    $total_error_count = array_reduce($errors, function ($carry, $item) {
      $carry += count($item);

      return $carry;
    });
    // Assert that there were 9 total errors across 3 displays.
    $this->assertIdentical(9, $total_error_count);
    $this->assertCount(3, $errors);
  }

  /**
   * Tests serialization of the ViewExecutable object.
   */
  public function testSerialization() {
    $view = Views::getView('test_executable_displays');
    $view->setDisplay('page_1');
    $view->setArguments(['test']);
    $view->setCurrentPage(2);

    $serialized = serialize($view);

    // Test the view storage object is not present in the actual serialized
    // string.
    $this->assertStringNotContainsString('"Drupal\views\Entity\View"', $serialized, 'The Drupal\views\Entity\View class was not found in the serialized string.');

    /** @var \Drupal\views\ViewExecutable $unserialized */
    $unserialized = unserialize($serialized);

    $this->assertInstanceOf(ViewExecutable::class, $unserialized);
    $this->assertIdentical($view->storage->id(), $unserialized->storage->id(), 'The expected storage entity was loaded on the unserialized view.');
    $this->assertIdentical($unserialized->current_display, 'page_1', 'The expected display was set on the unserialized view.');
    $this->assertIdentical($unserialized->args, ['test'], 'The expected argument was set on the unserialized view.');
    $this->assertIdentical($unserialized->getCurrentPage(), 2, 'The expected current page was set on the unserialized view.');

    // Get the definition of node's nid field, for example. Only get it not from
    // the field manager directly, but from the item data definition. It should
    // be the same base field definition object (the field and item definitions
    // refer to each other).
    // See https://bugs.php.net/bug.php?id=66052
    $field_manager = $this->container->get('entity_field.manager');
    $nid_definition_before = $field_manager->getBaseFieldDefinitions('node')['nid']
      ->getItemDefinition()
      ->getFieldDefinition();

    // Load and execute a view.
    $view_entity = View::load('content');
    $view_executable = $view_entity->getExecutable();
    $view_executable->execute('page_1');

    // Reset the static cache. Don't use clearCachedFieldDefinitions() since
    // that clears the persistent cache and we need to get the serialized cache
    // data.
    $field_manager->useCaches(FALSE);
    $field_manager->useCaches(TRUE);

    // Serialize the ViewExecutable as part of other data.
    unserialize(serialize(['SOMETHING UNEXPECTED', $view_executable]));

    // Make sure the serialization of the ViewExecutable didn't influence the
    // field definitions.
    $nid_definition_after = $field_manager->getBaseFieldDefinitions('node')['nid']
      ->getItemDefinition()
      ->getFieldDefinition();
    $this->assertEquals($nid_definition_before->getPropertyDefinitions(), $nid_definition_after->getPropertyDefinitions());
  }

  /**
   * Tests if argument overrides by validators are propagated to tokens.
   */
  public function testArgumentValidatorValueOverride() {
    $view = Views::getView('test_argument_dependency');
    $view->setDisplay('page_1');
    $view->setArguments(['1', 'this value should be replaced']);
    $view->execute();
    $expected = [
      '{{ arguments.uid }}' => '1',
      '{{ raw_arguments.uid }}' => '1',
    ];
    $this->assertEquals($expected, $view->build_info['substitutions']);
  }

}
