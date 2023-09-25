<?php

namespace Drupal\Tests\system\Functional\Menu;

use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests local tasks derived from router and added/altered via hooks.
 *
 * @group Menu
 */
class LocalTasksTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = ['block', 'menu_test', 'entity_test', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * The local tasks block under testing.
   *
   * @var \Drupal\block\Entity\Block
   */
  protected $sut;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->sut = $this->drupalPlaceBlock('local_tasks_block', ['id' => 'tabs_block']);
  }

  /**
   * Asserts local tasks in the page output.
   *
   * @param array $routes
   *   A list of expected local tasks, prepared as an array of route names and
   *   their associated route parameters, to assert on the page (in the given
   *   order).
   * @param int $level
   *   (optional) The local tasks level to assert; 0 for primary, 1 for
   *   secondary. Defaults to 0.
   *
   * @internal
   */
  protected function assertLocalTasks(array $routes, int $level = 0): void {
    $elements = $this->xpath('//*[contains(@class, :class)]//a', [
      ':class' => $level == 0 ? 'tabs primary' : 'tabs secondary',
    ]);
    $this->assertGreaterThan(0, count($elements), 'Local tasks found.');
    foreach ($routes as $index => $route_info) {
      [$route_name, $route_parameters] = $route_info;
      $expected = Url::fromRoute($route_name, $route_parameters)->toString();
      $this->assertEquals($expected, $elements[$index]->getAttribute('href'), "Task " . ($index + 1) . "number href " . $elements[$index]->getAttribute('href') . " equals $expected.");
    }
    $this->assertEquals(count($routes), count($elements), 'Only expected local tasks are found.');
  }

  /**
   * Ensures that some local task appears.
   *
   * @param string $title
   *   The expected title.
   *
   * @internal
   */
  protected function assertLocalTaskAppears(string $title): void {
    // SimpleXML gives us the unescaped text, not the actual escaped markup,
    // so use a pattern instead to check the raw content.
    // This behavior is a bug in libxml, see
    // https://bugs.php.net/bug.php?id=49437.
    $this->assertSession()->responseMatches('@<a [^>]*>' . preg_quote($title, '@') . '</a>@');
  }

  /**
   * Asserts that the local tasks on the specified level are not being printed.
   *
   * @param int $level
   *   (optional) The local tasks level to assert; 0 for primary, 1 for
   *   secondary. Defaults to 0.
   *
   * @internal
   */
  protected function assertNoLocalTasks(int $level = 0): void {
    $this->assertSession()->elementNotExists('xpath', '//*[contains(@class, "' . ($level == 0 ? 'tabs primary' : 'tabs secondary') . '")]//a');
  }

  /**
   * Tests the plugin based local tasks.
   */
  public function testPluginLocalTask() {
    // Verify local tasks defined in the hook.
    $this->drupalGet(Url::fromRoute('menu_test.tasks_default'));
    $this->assertLocalTasks([
      ['menu_test.tasks_default', []],
      ['menu_test.router_test1', ['bar' => 'unsafe']],
      ['menu_test.router_test1', ['bar' => '1']],
      ['menu_test.router_test2', ['bar' => '2']],
    ]);

    // Verify that script tags are escaped on output.
    $title = Html::escape("Task 1 <script>alert('Welcome to the jungle!')</script>");
    $this->assertLocalTaskAppears($title);
    $title = Html::escape("<script>alert('Welcome to the derived jungle!')</script>");
    $this->assertLocalTaskAppears($title);

    // Verify that local tasks appear as defined in the router.
    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_view'));
    $this->assertLocalTasks([
      ['menu_test.local_task_test_tasks_view', []],
      ['menu_test.local_task_test_tasks_edit', []],
      ['menu_test.local_task_test_tasks_settings', []],
      ['menu_test.local_task_test_tasks_settings_dynamic', []],
    ]);

    $title = Html::escape("<script>alert('Welcome to the jungle!')</script>");
    $this->assertLocalTaskAppears($title);

    // Ensure the view tab is active.
    $this->assertSession()->elementsCount('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]/a', 1);
    $this->assertSession()->elementTextEquals('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]/a', 'View(active tab)');

    // Verify that local tasks in the second level appear.
    $sub_tasks = [
      ['menu_test.local_task_test_tasks_settings_sub1', []],
      ['menu_test.local_task_test_tasks_settings_sub2', []],
      ['menu_test.local_task_test_tasks_settings_sub3', []],
      ['menu_test.local_task_test_tasks_settings_derived', ['placeholder' => 'derive1']],
      ['menu_test.local_task_test_tasks_settings_derived', ['placeholder' => 'derive2']],
    ];
    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_settings'));
    $this->assertLocalTasks($sub_tasks, 1);

    $this->assertSession()->elementsCount('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]/a', 1);
    $this->assertSession()->elementTextEquals('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]/a', 'Settings(active tab)');

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_settings_sub1'));
    $this->assertLocalTasks($sub_tasks, 1);

    $xpath = '//ul[contains(@class, "tabs")]//a[contains(@class, "active")]';
    $this->assertSession()->elementsCount('xpath', $xpath, 2);
    $links = $this->xpath($xpath);
    $this->assertEquals('Settings(active tab)', $links[0]->getText(), 'The settings tab is active.');
    $this->assertEquals('Dynamic title for TestTasksSettingsSub1(active tab)', $links[1]->getText(), 'The sub1 tab is active.');

    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'kittens:ragdoll');
    $this->assertSession()->responseHeaderContains('X-Drupal-Cache-Tags', 'kittens:dwarf-cat');

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_settings_derived', ['placeholder' => 'derive1']));
    $this->assertLocalTasks($sub_tasks, 1);

    $result = $this->xpath('//ul[contains(@class, "tabs")]//li[contains(@class, "active")]');
    $this->assertCount(2, $result, 'There are tabs active on both levels.');
    $this->assertEquals('Settings(active tab)', $result[0]->getText(), 'The settings tab is active.');
    $this->assertEquals('Derive 1(active tab)', $result[1]->getText(), 'The derive1 tab is active.');

    // Ensures that the local tasks contains the proper 'provider key'
    $definitions = $this->container->get('plugin.manager.menu.local_task')->getDefinitions();
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_view']['provider']);
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_edit']['provider']);
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_settings']['provider']);
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_settings_sub1']['provider']);
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_settings_sub2']['provider']);
    $this->assertEquals('menu_test', $definitions['menu_test.local_task_test_tasks_settings_sub3']['provider']);

    // Test that we correctly apply the active class to tabs where one of the
    // request attributes is upcast to an entity object.
    $entity = \Drupal::entityTypeManager()->getStorage('entity_test')->create(['bundle' => 'test']);
    $entity->save();

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_upcasting_sub1', ['entity_test' => '1']));

    $tasks = [
      ['menu_test.local_task_test_upcasting_sub1', ['entity_test' => '1']],
      ['menu_test.local_task_test_upcasting_sub2', ['entity_test' => '1']],
    ];

    $this->assertLocalTasks($tasks, 0);

    $this->assertSession()->elementsCount('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]', 1);
    $this->assertSession()->elementTextEquals('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]', 'upcasting sub1(active tab)');

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_upcasting_sub2', ['entity_test' => '1']));

    $tasks = [
      ['menu_test.local_task_test_upcasting_sub1', ['entity_test' => '1']],
      ['menu_test.local_task_test_upcasting_sub2', ['entity_test' => '1']],
    ];
    $this->assertLocalTasks($tasks, 0);

    $this->assertSession()->elementsCount('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]', 1);
    $this->assertSession()->elementTextEquals('xpath', '//ul[contains(@class, "tabs")]//li[contains(@class, "active")]', 'upcasting sub2(active tab)');
  }

  /**
   * Tests that local task blocks are configurable to show a specific level.
   */
  public function testLocalTaskBlock() {
    // Remove the default block and create a new one.
    $this->sut->delete();

    $this->sut = $this->drupalPlaceBlock('local_tasks_block', [
      'id' => 'tabs_block',
      'primary' => TRUE,
      'secondary' => FALSE,
    ]);

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_settings'));

    // Verify that local tasks in the first level appear.
    $this->assertLocalTasks([
      ['menu_test.local_task_test_tasks_view', []],
      ['menu_test.local_task_test_tasks_edit', []],
      ['menu_test.local_task_test_tasks_settings', []],
      ['menu_test.local_task_test_tasks_settings_dynamic', []],
    ]);

    // Verify that local tasks in the second level doesn't appear.
    $this->assertNoLocalTasks(1);

    $this->sut->delete();
    $this->sut = $this->drupalPlaceBlock('local_tasks_block', [
      'id' => 'tabs_block',
      'primary' => FALSE,
      'secondary' => TRUE,
    ]);

    $this->drupalGet(Url::fromRoute('menu_test.local_task_test_tasks_settings'));

    // Verify that local tasks in the first level doesn't appear.
    $this->assertNoLocalTasks(0);

    // Verify that local tasks in the second level appear.
    $sub_tasks = [
      ['menu_test.local_task_test_tasks_settings_sub1', []],
      ['menu_test.local_task_test_tasks_settings_sub2', []],
      ['menu_test.local_task_test_tasks_settings_sub3', []],
      ['menu_test.local_task_test_tasks_settings_derived', ['placeholder' => 'derive1']],
      ['menu_test.local_task_test_tasks_settings_derived', ['placeholder' => 'derive2']],
    ];
    $this->assertLocalTasks($sub_tasks, 1);
  }

  /**
   * Tests that local tasks blocks cache is invalidated correctly.
   */
  public function testLocalTaskBlockCache() {
    $this->drupalLogin($this->rootUser);
    $this->drupalCreateContentType(['type' => 'page']);

    // Only the Edit task. The block avoids showing a single tab.
    $this->drupalGet('/admin/config/people/accounts');
    $this->assertNoLocalTasks();

    // Only the Edit and Manage permission tabs.
    $this->drupalGet('/admin/structure/types/manage/page');
    $this->assertLocalTasks([
      ['entity.node_type.edit_form', ['node_type' => 'page']],
      ['entity.node_type.entity_permissions_form', ['node_type' => 'page']],
    ]);

    // Field UI adds the usual Manage fields etc tabs.
    \Drupal::service('module_installer')->install(['field_ui']);
    $this->drupalGet('/admin/structure/types/manage/page');
    $this->assertLocalTasks([
      ['entity.node_type.edit_form', ['node_type' => 'page']],
      ['entity.node.field_ui_fields', ['node_type' => 'page']],
      ['entity.entity_form_display.node.default', ['node_type' => 'page']],
      ['entity.entity_view_display.node.default', ['node_type' => 'page']],
      ['entity.node_type.entity_permissions_form', ['node_type' => 'page']],
    ]);
  }

}
