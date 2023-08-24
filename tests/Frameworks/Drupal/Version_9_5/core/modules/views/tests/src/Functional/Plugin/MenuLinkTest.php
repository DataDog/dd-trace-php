<?php

namespace Drupal\Tests\views\Functional\Plugin;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\Tests\views\Functional\ViewTestBase;

/**
 * Tests the menu links created in views.
 *
 * @group views
 */
class MenuLinkTest extends ViewTestBase {

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_menu_link'];

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'views',
    'views_ui',
    'user',
    'node',
    'menu_link_content',
    'block',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to administer views, menus and view content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE, $modules = ['views_test_config']): void {
    parent::setUp($import_test_views, $modules);

    $this->enableViewsTestModule();

    $this->adminUser = $this->drupalCreateUser([
      'administer views',
      'administer menu',
    ]);
    $this->drupalPlaceBlock('system_menu_block:main');
    $this->drupalCreateContentType(['type' => 'page']);
  }

  /**
   * Tests that menu links using menu_link_content as parent are visible.
   */
  public function testHierarchicalMenuLinkVisibility() {
    $this->drupalLogin($this->adminUser);

    $node = $this->drupalCreateNode(['type' => 'page']);

    // Create a primary level menu link to the node.
    $link = MenuLinkContent::create([
      'title' => 'Primary level node',
      'menu_name' => 'main',
      'bundle' => 'menu_link_content',
      'parent' => '',
      'link' => [['uri' => 'entity:node/' . $node->id()]],
    ]);
    $link->save();

    $parent_menu_value = 'main:menu_link_content:' . $link->uuid();

    // Alter the view's menu link in view page to use the menu link from the
    // node as parent.
    $this->drupalGet("admin/structure/views/nojs/display/test_menu_link/page_1/menu");
    $this->submitForm([
      'menu[type]' => 'normal',
      'menu[title]' => 'Secondary level view page',
      'menu[parent]' => $parent_menu_value,
    ], 'Apply');

    // Save view which has pending changes.
    $this->submitForm([], 'Save');

    // Test if the node as parent menu item is selected in our views settings.
    $this->drupalGet('admin/structure/views/nojs/display/test_menu_link/page_1/menu');
    $this->assertTrue($this->assertSession()->optionExists('edit-menu-parent', $parent_menu_value)->isSelected());

    $this->drupalGet('');

    // Test if the primary menu item (node) is visible, and the secondary menu
    // item (view) is hidden.
    $this->assertSession()->pageTextContains('Primary level node');
    $this->assertSession()->pageTextNotContains('Secondary level view page');

    // Go to the node page and ensure that both the first and second level items
    // are visible.
    $this->drupalGet($node->toUrl());
    $this->assertSession()->pageTextContains('Primary level node');
    $this->assertSession()->pageTextContains('Secondary level view page');
  }

}
