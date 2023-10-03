<?php

namespace Drupal\Tests\views_ui\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\views\Entity\View;
use Drupal\views\Tests\ViewTestData;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Tests the display UI.
 *
 * @group views_ui
 */
class DisplayTest extends WebDriverTestBase {

  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'block',
    'contextual',
    'node',
    'views',
    'views_ui',
    'views_test_config',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'classy';

  public static $testViews = ['test_content_ajax', 'test_display'];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    ViewTestData::createTestViews(self::class, ['views_test_config']);

    $admin_user = $this->drupalCreateUser([
      'administer site configuration',
      'administer views',
      'administer nodes',
      'access content overview',
      'access contextual links',
    ]);

    // Disable automatic live preview to make the sequence of calls clearer.
    \Drupal::configFactory()->getEditable('views.settings')->set('ui.always_live_preview', FALSE)->save();
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests adding a display.
   */
  public function testAddDisplay() {
    $this->drupalGet('admin/structure/views/view/test_content_ajax');
    $page = $this->getSession()->getPage();

    $page->find('css', '#views-display-menu-tabs .add')->click();

    // Wait for the animation to complete.
    $this->assertSession()->assertWaitOnAjaxRequest();

    // Add the display.
    $page->find('css', '#edit-displays-top-add-display-block')->click();

    $element = $page->findById('views-display-menu-tabs')->findLink('Block');
    $this->assertNotEmpty($element);
  }

  /**
   * Tests contextual links on Views page displays.
   */
  public function testPageContextualLinks() {
    $view = View::load('test_display');
    $view->enable()->save();
    $this->container->get('router.builder')->rebuildIfNeeded();

    // Create node so the view has content and the contextual area is higher
    // than 0 pixels.
    $this->drupalCreateContentType(['type' => 'page']);
    $this->createNode();

    // When no "main content" block is placed, we find a contextual link
    // placeholder for editing just the view.
    $this->drupalGet('test-display');
    $page = $this->getSession()->getPage();
    $this->assertSession()->assertWaitOnAjaxRequest();

    $selector = '.view-test-display';
    $this->toggleContextualTriggerVisibility($selector);

    $element = $this->getSession()->getPage()->find('css', $selector);
    $element->find('css', '.contextual button')->press();

    $contextual_container_id = 'entity.view.edit_form:view=test_display:location=page&name=test_display&display_id=page_1&langcode=en';
    $contextual_container = $page->find('css', '[data-contextual-id="' . $contextual_container_id . '"]');
    $this->assertNotEmpty($contextual_container);

    $edit_link = $contextual_container->findLink('Edit view');
    $this->assertNotEmpty($edit_link);

    // When a "main content" is placed, we still find a contextual link
    // placeholder for editing just the view (not the main content block).
    // @see system_block_view_system_main_block_alter()
    $this->drupalPlaceBlock('system_main_block', ['id' => 'main_content']);
    $contextual_container = $page->find('css', '[data-contextual-id="' . $contextual_container_id . '"]');
    $this->assertNotEmpty($contextual_container);
  }

  /**
   * Toggles the visibility of a contextual trigger.
   *
   * @param string $selector
   *   The selector for the element that contains the contextual Rink.
   */
  protected function toggleContextualTriggerVisibility($selector) {
    // Hovering over the element itself with should be enough, but does not
    // work. Manually remove the visually-hidden class.
    $this->getSession()->executeScript("jQuery('{$selector} .contextual .trigger').toggleClass('visually-hidden');");
  }

  /**
   * Confirms that form_alter is triggered after ajax rebuilds.
   */
  public function testAjaxRebuild() {
    \Drupal::service('theme_installer')->install(['views_test_classy_subtheme']);

    $this->config('system.theme')
      ->set('default', 'views_test_classy_subtheme')
      ->save();

    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();

    $this->drupalGet('admin/structure/views/view/content');
    $assert_session->pageTextContains('This is text added to the display tabs at the top');
    $assert_session->pageTextContains('This is text added to the display edit form');
    $page->clickLink('Content: Title (Title)');
    $assert_session->waitForElementVisible('css', '.views-ui-dialog');
    $page->fillField('Label', 'New Title');
    $page->find('css', '.ui-dialog-buttonset button:contains("Apply")')->press();
    $assert_session->waitForElementRemoved('css', '.views-ui-dialog');
    $assert_session->pageTextContains('This is text added to the display tabs at the top');
    $assert_session->pageTextContains('This is text added to the display edit form');
  }

}
