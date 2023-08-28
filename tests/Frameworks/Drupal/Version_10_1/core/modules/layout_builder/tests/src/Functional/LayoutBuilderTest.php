<?php

namespace Drupal\Tests\layout_builder\Functional;

use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;

/**
 * Tests the Layout Builder UI.
 *
 * @group layout_builder
 */
class LayoutBuilderTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field_ui',
    'views',
    'layout_builder',
    'layout_builder_views_test',
    'layout_test',
    'block',
    'block_test',
    'contextual',
    'node',
    'layout_builder_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'starterkit_theme';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->drupalPlaceBlock('local_tasks_block');

    // Create two nodes.
    $this->createContentType([
      'type' => 'bundle_with_section_field',
      'name' => 'Bundle with section field',
    ]);
    $this->createNode([
      'type' => 'bundle_with_section_field',
      'title' => 'The first node title',
      'body' => [
        [
          'value' => 'The first node body',
        ],
      ],
    ]);
    $this->createNode([
      'type' => 'bundle_with_section_field',
      'title' => 'The second node title',
      'body' => [
        [
          'value' => 'The second node body',
        ],
      ],
    ]);
  }

  /**
   * Tests deleting a field in-use by an overridden layout.
   */
  public function testDeleteField() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node fields',
    ]));

    // Enable layout builder overrides.
    LayoutBuilderEntityViewDisplay::load('node.bundle_with_section_field.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    // Ensure there is a layout override.
    $this->drupalGet('node/1/layout');
    $page->pressButton('Save layout');

    // Delete one of the fields in use.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/fields/node.bundle_with_section_field.body/delete');
    $page->pressButton('Delete');

    // The node should still be accessible.
    $this->drupalGet('node/1');
    $assert_session->statusCodeEquals(200);
    $this->drupalGet('node/1/layout');
    $assert_session->statusCodeEquals(200);
  }

  /**
   * Tests Layout Builder overrides without access to edit the default layout.
   */
  public function testOverridesWithoutDefaultsAccess() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser(['configure any layout']));

    LayoutBuilderEntityViewDisplay::load('node.bundle_with_section_field.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $this->drupalGet('node/1');
    $page->clickLink('Layout');
    $assert_session->elementTextContains('css', '.layout-builder__message.layout-builder__message--overrides', 'You are editing the layout for this Bundle with section field content item.');
    $assert_session->linkNotExists('Edit the template for all Bundle with section field content items instead.');
  }

  /**
   * Tests Layout Builder overrides without Field UI installed.
   */
  public function testOverridesWithoutFieldUi() {
    $this->container->get('module_installer')->uninstall(['field_ui']);

    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    // @todo In https://www.drupal.org/node/540008 switch this to logging in as
    //   a user with the 'configure any layout' permission.
    $this->drupalLogin($this->rootUser);

    LayoutBuilderEntityViewDisplay::load('node.bundle_with_section_field.default')
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $this->drupalGet('node/1');
    $page->clickLink('Layout');
    $assert_session->elementTextContains('css', '.layout-builder__message.layout-builder__message--overrides', 'You are editing the layout for this Bundle with section field content item.');
    $assert_session->linkNotExists('Edit the template for all Bundle with section field content items instead.');
  }

  /**
   * Tests functionality of Layout Builder for overrides.
   */
  public function testOverrides() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    // From the manage display page, go to manage the layout.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    // @todo This should not be necessary.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    // Add a block with a custom label.
    $this->drupalGet('node/1');
    $page->clickLink('Layout');
    // The layout form should not contain fields for the title of the node by
    // default.
    $assert_session->fieldNotExists('title[0][value]');
    $assert_session->elementTextContains('css', '.layout-builder__message.layout-builder__message--overrides', 'You are editing the layout for this Bundle with section field content item. Edit the template for all Bundle with section field content items instead.');
    $assert_session->linkExists('Edit the template for all Bundle with section field content items instead.');
    $page->clickLink('Add block');
    $page->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is an override');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $page->pressButton('Save layout');
    $assert_session->pageTextContains('This is an override');

    // Get the UUID of the component.
    $components = Node::load(1)->get('layout_builder__layout')->getSection(0)->getComponents();
    end($components);
    $uuid = key($components);

    $this->drupalGet('layout_builder/update/block/overrides/node.1/0/content/' . $uuid);
    $page->uncheckField('settings[label_display]');
    $page->pressButton('Update');
    $assert_session->pageTextNotContains('This is an override');
    $page->pressButton('Save layout');
    $assert_session->pageTextNotContains('This is an override');
  }

  /**
   * Tests the Layout Builder UI for an entity type without a bundle.
   */
  public function testNonBundleEntityType() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    // Log in as a user that can edit layout templates.
    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer user display',
    ]));

    $this->drupalGet('admin/config/people/accounts/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    $page->clickLink('Manage layout');
    $assert_session->pageTextContains('You are editing the layout template for all users.');

    $this->drupalGet('user');
    $page->clickLink('Layout');
    $assert_session->pageTextContains('You are editing the layout for this user. Edit the template for all users instead.');

    // Log in as a user that cannot edit layout templates.
    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
    ]));
    $this->drupalGet('user');
    $page->clickLink('Layout');
    $assert_session->pageTextContains('You are editing the layout for this user.');
    $assert_session->pageTextNotContains('Edit the template for all users instead.');
  }

  /**
   * Tests that the Layout Builder preserves entity values.
   */
  public function testPreserverEntityValues() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    // From the manage display page, go to manage the layout.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    // @todo This should not be necessary.
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();

    $this->drupalGet('node/1');
    $assert_session->pageTextContains('The first node body');

    // Create a layout override which will store the current node in the
    // tempstore.
    $page->clickLink('Layout');
    $page->clickLink('Add block');
    $page->clickLink('Powered by Drupal');
    $page->pressButton('Add block');

    // Update the node to make a change that is not in the tempstore version.
    $node = Node::load(1);
    $node->set('body', 'updated body');
    $node->save();

    $page->clickLink('View');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextContains('updated body');

    $page->clickLink('Layout');
    $page->pressButton('Save layout');

    // Ensure that saving the layout does not revert other field values.
    $assert_session->addressEquals('node/1');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextContains('updated body');
  }

  /**
   * {@inheritdoc}
   */
  public function testLayoutBuilderUi() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
    ]));

    $this->drupalGet('node/1');
    $assert_session->elementNotExists('css', '.layout-builder-block');
    $assert_session->pageTextContains('The first node body');
    $assert_session->pageTextNotContains('Powered by Drupal');
    $assert_session->linkNotExists('Layout');

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';

    // From the manage display page, go to manage the layout.
    $this->drupalGet("$field_ui_prefix/display/default");
    $assert_session->linkNotExists('Manage layout');
    $assert_session->fieldDisabled('layout[allow_custom]');

    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $assert_session->linkExists('Manage layout');
    $this->clickLink('Manage layout');
    $assert_session->addressEquals("$field_ui_prefix/display/default/layout");
    $assert_session->elementTextContains('css', '.layout-builder__message.layout-builder__message--defaults', 'You are editing the layout template for all Bundle with section field content items.');
    // The body field is only present once.
    $assert_session->elementsCount('css', '.field--name-body', 1);
    // The extra field is only present once.
    $assert_session->pageTextContainsOnce('Placeholder for the "Extra label" field');
    // Blocks have layout builder specific block class.
    $assert_session->elementExists('css', '.layout-builder-block');
    // Save the defaults.
    $page->pressButton('Save layout');
    $assert_session->addressEquals("$field_ui_prefix/display/default");

    // Load the default layouts again after saving to confirm fields are only
    // added on new layouts.
    $this->drupalGet("$field_ui_prefix/display/default");
    $assert_session->linkExists('Manage layout');
    $this->clickLink('Manage layout');
    $assert_session->addressEquals("$field_ui_prefix/display/default/layout");
    // The body field is only present once.
    $assert_session->elementsCount('css', '.field--name-body', 1);
    // The extra field is only present once.
    $assert_session->pageTextContainsOnce('Placeholder for the "Extra label" field');

    // Add a new block.
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->linkExists('Powered by Drupal');
    $this->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is the label');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextContains('This is the label');
    $assert_session->addressEquals("$field_ui_prefix/display/default/layout");

    // Save the defaults.
    $page->pressButton('Save layout');
    $assert_session->pageTextContains('The layout has been saved.');
    $assert_session->addressEquals("$field_ui_prefix/display/default");

    // The node uses the defaults, no overrides available.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('The first node body');
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextContains('Extra, Extra read all about it.');
    $assert_session->pageTextNotContains('Placeholder for the "Extra label" field');
    $assert_session->linkNotExists('Layout');
    $assert_session->pageTextContains(sprintf('Yes, I can access the %s', Node::load(1)->label()));

    // Enable overrides.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    $this->drupalGet('node/1');

    // Remove the section from the defaults.
    $assert_session->linkExists('Layout');
    $this->clickLink('Layout');
    $assert_session->pageTextContains('Placeholder for the "Extra label" field');
    $assert_session->linkExists('Remove Section 1');
    $this->clickLink('Remove Section 1');
    $page->pressButton('Remove');

    // Add a new section.
    $this->clickLink('Add section');
    $this->assertCorrectLayouts();
    $assert_session->linkExists('Two column');
    $this->clickLink('Two column');
    $assert_session->buttonExists('Add section');
    $page->pressButton('Add section');
    $page->pressButton('Save');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextNotContains('Powered by Drupal');
    $assert_session->pageTextNotContains('Extra, Extra read all about it.');
    $assert_session->pageTextNotContains('Placeholder for the "Extra label" field');
    $assert_session->pageTextContains(sprintf('Yes, I can access the entity %s in two column', Node::load(1)->label()));

    // Assert that overrides cannot be turned off while overrides exist.
    $this->drupalGet("$field_ui_prefix/display/default");
    $assert_session->checkboxChecked('layout[allow_custom]');
    $assert_session->fieldDisabled('layout[allow_custom]');

    // Alter the defaults.
    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->linkExists('Title');
    $this->clickLink('Title');
    $page->pressButton('Add block');
    // The title field is present.
    $assert_session->elementExists('css', '.field--name-title');
    $page->pressButton('Save layout');

    // View the other node, which is still using the defaults.
    $this->drupalGet('node/2');
    $assert_session->pageTextContains('The second node title');
    $assert_session->pageTextContains('The second node body');
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextContains('Extra, Extra read all about it.');
    $assert_session->pageTextNotContains('Placeholder for the "Extra label" field');
    $assert_session->pageTextContains(sprintf('Yes, I can access the %s', Node::load(2)->label()));

    // The overridden node does not pick up the changes to defaults.
    $this->drupalGet('node/1');
    $assert_session->elementNotExists('css', '.field--name-title');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextNotContains('Powered by Drupal');
    $assert_session->pageTextNotContains('Extra, Extra read all about it.');
    $assert_session->pageTextNotContains('Placeholder for the "Extra label" field');
    $assert_session->linkExists('Layout');

    // Reverting the override returns it to the defaults.
    $this->clickLink('Layout');
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->linkExists('ID');
    $this->clickLink('ID');
    $page->pressButton('Add block');
    // The title field is present.
    $assert_session->elementExists('css', '.field--name-nid');
    $assert_session->pageTextContains('ID');
    $assert_session->pageTextContains('1');
    $page->pressButton('Revert to defaults');
    $page->pressButton('Revert');
    $assert_session->addressEquals('node/1');
    $assert_session->pageTextContains('The layout has been reverted back to defaults.');
    $assert_session->elementExists('css', '.field--name-title');
    $assert_session->elementNotExists('css', '.field--name-nid');
    $assert_session->pageTextContains('The first node body');
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextContains('Extra, Extra read all about it.');
    $assert_session->pageTextNotContains(sprintf('Yes, I can access the entity %s in two column', Node::load(1)->label()));
    $assert_session->pageTextContains(sprintf('Yes, I can access the %s', Node::load(1)->label()));

    // Assert that overrides can be turned off now that all overrides are gone.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => FALSE], 'Save');
    $this->drupalGet('node/1');
    $assert_session->linkNotExists('Layout');

    // Add a new field.
    $edit = [
      'new_storage_type' => 'string',
      'label' => 'My text field',
      'field_name' => 'my_text',
    ];
    $this->drupalGet("{$field_ui_prefix}/fields/add-field");
    $this->submitForm($edit, 'Save and continue');
    $page->pressButton('Save field settings');
    $page->pressButton('Save settings');
    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $assert_session->pageTextContains('My text field');
    $assert_session->elementExists('css', '.field--name-field-my-text');

    // Delete the field.
    $this->drupalGet("{$field_ui_prefix}/fields/node.bundle_with_section_field.field_my_text/delete");
    $this->submitForm([], 'Delete');
    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $assert_session->pageTextNotContains('My text field');
    $assert_session->elementNotExists('css', '.field--name-field-my-text');

    $this->clickLink('Add section');
    $this->clickLink('One column');
    $page->fillField('layout_settings[label]', 'My Cool Section');
    $page->pressButton('Add section');

    $expected_labels = [
      'My Cool Section',
      'Content region in My Cool Section',
      'Section 2',
      'Content region in Section 2',
    ];
    $labels = [];
    foreach ($page->findAll('css', '[role="group"]') as $element) {
      $labels[] = $element->getAttribute('aria-label');
    }
    $this->assertSame($expected_labels, $labels);
  }

  /**
   * Test decorating controller.entity_form while layout_builder is installed.
   */
  public function testHtmlEntityFormControllerDecoration() {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    // Install module that decorates controller.entity_form.
    \Drupal::service('module_installer')->install(['layout_builder_decoration_test']);
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $assert_session->pageTextContains('Manage Display');
  }

  /**
   * Tests that layout builder checks entity view access.
   */
  public function testAccess() {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Allow overrides for the layout.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    $this->drupalLogin($this->drupalCreateUser(['configure any layout']));
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('The first node body');
    $assert_session->pageTextNotContains('Powered by Drupal');
    $node = Node::load(1);
    $node->setUnpublished();
    $node->save();
    $this->drupalGet('node/1');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextContains('Access denied');

    $this->drupalGet('node/1/layout');
    $assert_session->pageTextNotContains('The first node body');
    $assert_session->pageTextContains('Access denied');
  }

  /**
   * Tests that a non-default view mode works as expected.
   */
  public function testNonDefaultViewMode() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Allow overrides for the layout.
    $this->drupalGet("$field_ui_prefix/display/default");
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');
    $page->checkField('layout[allow_custom]');
    $page->pressButton('Save');

    $this->clickLink('Manage layout');
    // Confirm the body field only is shown once.
    $assert_session->elementsCount('css', '.field--name-body', 1);
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    $this->clickLink('Teaser');
    // Enabling Layout Builder for the default mode does not affect the teaser.
    $assert_session->addressEquals("$field_ui_prefix/display/teaser");
    $assert_session->elementNotExists('css', '#layout-builder__layout');
    $assert_session->checkboxNotChecked('layout[enabled]');
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');
    $assert_session->linkExists('Manage layout');
    $page->clickLink('Manage layout');
    // Confirm the body field only is shown once.
    $assert_session->elementsCount('css', '.field--name-body', 1);

    // Enable a disabled view mode.
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');
    $assert_session->addressEquals("$field_ui_prefix/display/teaser");
    $page->clickLink('Default');
    $assert_session->addressEquals("$field_ui_prefix/display");
    $assert_session->linkNotExists('Full content');
    $page->checkField('display_modes_custom[full]');
    $page->pressButton('Save');

    $assert_session->linkExists('Full content');
    $page->clickLink('Full content');
    $assert_session->addressEquals("$field_ui_prefix/display/full");
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');
    $assert_session->linkExists('Manage layout');
    $page->clickLink('Manage layout');
    // Confirm the body field only is shown once.
    $assert_session->elementsCount('css', '.field--name-body', 1);
  }

  /**
   * Tests that component's dependencies are respected during removal.
   */
  public function testPluginDependencies() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->container->get('module_installer')->install(['menu_ui']);
    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer menu',
    ]));

    // Create a new menu.
    $this->drupalGet('admin/structure/menu/add');
    $page->fillField('label', 'My Menu');
    $page->fillField('id', 'my-menu');
    $page->pressButton('Save');
    $this->drupalGet('admin/structure/menu/add');
    $page->fillField('label', 'My Menu');
    $page->fillField('id', 'my-other-menu');
    $page->pressButton('Save');

    $page->clickLink('Add link');
    $page->fillField('title[0][value]', 'My link');
    $page->fillField('link[0][uri]', '/');
    $page->pressButton('Save');

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $assert_session->linkExists('Manage layout');
    $this->clickLink('Manage layout');
    $assert_session->linkExists('Add section');
    $this->clickLink('Add section');
    $assert_session->linkExists('Layout plugin (with dependencies)');
    $this->clickLink('Layout plugin (with dependencies)');
    $page->pressButton('Add section');
    $assert_session->elementExists('css', '.layout--layout-test-dependencies-plugin');
    $assert_session->elementExists('css', '.field--name-body');
    $page->pressButton('Save layout');
    $this->drupalGet('admin/structure/menu/manage/my-other-menu/delete');
    $this->submitForm([], 'Delete');
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default/layout');
    $assert_session->elementNotExists('css', '.layout--layout-test-dependencies-plugin');
    $assert_session->elementExists('css', '.field--name-body');

    // Add a menu block.
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->linkExists('My Menu');
    $this->clickLink('My Menu');
    $page->pressButton('Add block');

    // Add another block alongside the menu.
    $assert_session->linkExists('Add block');
    $this->clickLink('Add block');
    $assert_session->linkExists('Powered by Drupal');
    $this->clickLink('Powered by Drupal');
    $page->pressButton('Add block');

    // Assert that the blocks are visible, and save the layout.
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextContains('My Menu');
    $assert_session->elementExists('css', '.block.menu--my-menu');
    $page->pressButton('Save layout');

    // Delete the menu.
    $this->drupalGet('admin/structure/menu/manage/my-menu/delete');
    $this->submitForm([], 'Delete');

    // Ensure that the menu block is gone, but that the other block remains.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default/layout');
    $assert_session->pageTextContains('Powered by Drupal');
    $assert_session->pageTextNotContains('My Menu');
    $assert_session->elementNotExists('css', '.block.menu--my-menu');
  }

  /**
   * Tests that block plugins can define custom attributes and contextual links.
   */
  public function testPluginsProvidingCustomAttributesAndContextualLinks() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'access contextual links',
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('Layout Builder Test Plugin');
    $page->pressButton('Add section');
    $page->clickLink('Add block');
    $page->clickLink('Test Attributes');
    $page->pressButton('Add block');
    $page->pressButton('Save layout');

    $this->drupalGet('node/1');

    $assert_session->elementExists('css', '.attribute-test-class');
    $assert_session->elementExists('css', '[custom-attribute=test]');
    $assert_session->elementExists('css', 'div[data-contextual-id*="layout_builder_test"]');
  }

  /**
   * Tests preview-aware layout & block plugins.
   */
  public function testPreviewAwarePlugins() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('Layout Builder Test Plugin');
    $page->pressButton('Add section');
    $page->clickLink('Add block');
    $page->clickLink('Preview-aware block');
    $page->pressButton('Add block');

    $assert_session->elementExists('css', '.go-birds-preview');
    $assert_session->pageTextContains('The block template is being previewed.');
    $assert_session->pageTextContains('This block is being rendered in preview mode.');

    $page->pressButton('Save layout');
    $this->drupalGet('node/1');

    $assert_session->elementNotExists('css', '.go-birds-preview');
    $assert_session->pageTextNotContains('The block template is being previewed.');
    $assert_session->pageTextContains('This block is being rendered normally.');
  }

  /**
   * Tests preview-aware templates.
   */
  public function testPreviewAwareTemplates() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('1 column layout');
    $page->pressButton('Add section');
    $page->clickLink('Add block');
    $page->clickLink('Preview-aware block');
    $page->pressButton('Add block');

    $assert_session->pageTextContains('This is a preview, indeed');

    $page->pressButton('Save layout');
    $this->drupalGet('node/1');

    $assert_session->pageTextNotContains('This is a preview, indeed');
  }

  /**
   * Tests the interaction between full and default view modes.
   *
   * @see \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage::getDefaultSectionStorage()
   */
  public function testLayoutBuilderUiFullViewMode() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';

    // For the purposes of this test, turn the full view mode on and off to
    // prevent copying from the customized default view mode.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => FALSE], 'Save');

    // Allow overrides for the layout.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    // Customize the default view mode.
    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $this->clickLink('Add block');
    $this->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is the default view mode');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('This is the default view mode');
    $page->pressButton('Save layout');

    // The default view mode is used for both the node display and layout UI.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    // Enable the full view mode and customize it.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/full");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/full");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    $this->drupalGet("$field_ui_prefix/display/full/layout");
    $this->clickLink('Add block');
    $this->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is the full view mode');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('This is the full view mode');
    $page->pressButton('Save layout');

    // The full view mode is now used for both the node display and layout UI.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    // Disable the full view mode, the default should be used again.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => FALSE], 'Save');
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    // Re-enabling the full view mode restores the layout changes.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => TRUE], 'Save');
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');

    // Create an override of the full view mode.
    $this->clickLink('Add block');
    $this->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is an override of the full view mode');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $page->pressButton('Save layout');

    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    // The override does not affect the full view mode.
    $this->drupalGet("$field_ui_prefix/display/full/layout");
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');

    // Reverting the override restores back to the full view mode.
    $this->drupalGet('node/1/layout');
    $page->pressButton('Revert to default');
    $page->pressButton('Revert');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextNotContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');

    // Recreate an override of the full view mode.
    $this->clickLink('Add block');
    $this->clickLink('Powered by Drupal');
    $page->fillField('settings[label]', 'This is an override of the full view mode');
    $page->checkField('settings[label_display]');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $page->pressButton('Save layout');

    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');

    // Disable the full view mode.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['display_modes_custom[full]' => FALSE], 'Save');

    // The override of the full view mode is still available.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('This is the full view mode');
    $assert_session->pageTextContains('This is an override of the full view mode');
    $assert_session->pageTextNotContains('This is the default view mode');

    // Reverting the override restores back to the default view mode.
    $this->drupalGet('node/1/layout');
    $page->pressButton('Revert to default');
    $page->pressButton('Revert');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $this->drupalGet('node/1/layout');
    $assert_session->pageTextContains('This is the default view mode');
    $assert_session->pageTextNotContains('This is the full view mode');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');
  }

  /**
   * Ensures that one bundle doesn't interfere with another bundle.
   */
  public function testFullViewModeMultipleBundles() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    // Create one bundle with the full view mode enabled.
    $this->createContentType(['type' => 'full_bundle']);
    $this->drupalGet('admin/structure/types/manage/full_bundle/display/default');
    $page->checkField('display_modes_custom[full]');
    $page->pressButton('Save');

    // Create another bundle without the full view mode enabled.
    $this->createContentType(['type' => 'default_bundle']);
    $this->drupalGet('admin/structure/types/manage/default_bundle/display/default');

    // Enable Layout Builder for defaults and overrides.
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');
    $page->checkField('layout[allow_custom]');
    $page->pressButton('Save');
    $assert_session->checkboxChecked('layout[allow_custom]');
  }

  /**
   * {@inheritdoc}
   */
  public function testLayoutBuilderChooseBlocksAlter() {
    // See layout_builder_test_plugin_filter_block__layout_builder_alter().
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
    ]));

    // From the manage display page, go to manage the layout.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $assert_session->linkExists('Manage layout');
    $this->clickLink('Manage layout');

    // Add a new block.
    $this->clickLink('Add block');

    // Verify that blocks not modified are present.
    $assert_session->linkExists('Powered by Drupal');
    $assert_session->linkExists('Default revision');

    // Verify that blocks explicitly removed are not present.
    $assert_session->linkNotExists('Help');
    $assert_session->linkNotExists('Sticky at top of lists');
    $assert_session->linkNotExists('Main page content');
    $assert_session->linkNotExists('Page title');

    // Verify that Changed block is not present on first section.
    $assert_session->linkNotExists('Changed');

    // Go back to Manage layout.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->clickLink('Manage layout');

    // Add a new section.
    $this->clickLink('Add section', 1);
    $assert_session->linkExists('Two column');
    $this->clickLink('Two column');
    $assert_session->buttonExists('Add section');
    $this->getSession()->getPage()->pressButton('Add section');
    // Add a new block to second section.
    $this->clickLink('Add block', 1);

    // Verify that Changed block is present on second section.
    $assert_session->linkExists('Changed');
  }

  /**
   * Tests that extra fields work before and after enabling Layout Builder.
   */
  public function testExtraFields() {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('node');
    $assert_session->linkExists('Read more');

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');

    // Extra fields display under "Content fields".
    $this->drupalGet("admin/structure/types/manage/bundle_with_section_field/display/default/layout");
    $this->clickLink('Add block');
    $assert_session->elementTextContains('xpath', '//details/summary[contains(text(),"Content fields")]/parent::details', 'Extra label');

    $this->drupalGet('node');
    $assert_session->linkExists('Read more');

    // Consider an extra field hidden by default. Make sure it's not displayed.
    $this->drupalGet('node/1');
    $assert_session->pageTextNotContains('Extra Field 2 is hidden by default.');

    // View the layout and add the extra field that is not visible by default.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default/layout');
    $assert_session->pageTextNotContains('Extra Field 2');
    $page = $this->getSession()->getPage();
    $page->clickLink('Add block');
    $page->clickLink('Extra Field 2');
    $page->pressButton('Add block');
    $assert_session->pageTextContains('Extra Field 2');
    $page->pressButton('Save layout');

    // Confirm that the newly added extra field is visible.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('Extra Field 2 is hidden by default.');
  }

  /**
   * Tests loading a pending revision in the Layout Builder UI.
   */
  public function testPendingRevision() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Enable overrides.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node = $storage->load(1);
    // Create a pending revision.
    $pending_revision = $storage->createRevision($node, FALSE);
    $pending_revision->set('title', 'The pending title of the first node');
    $pending_revision->save();

    // The original node title is available when viewing the node, but the
    // pending title is visible within the Layout Builder UI.
    $this->drupalGet('node/1');
    $assert_session->pageTextContains('The first node title');
    $page->clickLink('Layout');
    $assert_session->pageTextNotContains('The first node title');
    $assert_session->pageTextContains('The pending title of the first node');
  }

  /**
   * Tests that deleting a View block used in Layout Builder works.
   */
  public function testDeletedView() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Enable overrides.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    $this->drupalGet('node/1');

    $assert_session->linkExists('Layout');
    $this->clickLink('Layout');
    $this->clickLink('Add block');
    $this->clickLink('Test Block View');
    $page->pressButton('Add block');

    $assert_session->pageTextContains('Test Block View');
    $assert_session->elementExists('css', '.block-views-blocktest-block-view-block-1');
    $page->pressButton('Save');
    $assert_session->pageTextContains('Test Block View');
    $assert_session->elementExists('css', '.block-views-blocktest-block-view-block-1');

    View::load('test_block_view')->delete();
    $this->drupalGet('node/1');
    // Node can be loaded after deleting the View.
    $assert_session->pageTextContains(Node::load(1)->getTitle());
    $assert_session->pageTextNotContains('Test Block View');
  }

  /**
   * Tests that hook_form_alter() has access to the Layout Builder info.
   */
  public function testFormAlter() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    $this->drupalGet("$field_ui_prefix/display/default");
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');

    $page->clickLink('Manage layout');
    $page->clickLink('Add block');
    $page->clickLink('Powered by Drupal');
    $assert_session->pageTextContains('Layout Builder Storage: node.bundle_with_section_field.default');
    $assert_session->pageTextContains('Layout Builder Section: layout_onecol');
    $assert_session->pageTextContains('Layout Builder Component: system_powered_by_block');

    $this->drupalGet("$field_ui_prefix/display/default");
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('One column');
    $assert_session->pageTextContains('Layout Builder Storage: node.bundle_with_section_field.default');
    $assert_session->pageTextContains('Layout Builder Section: layout_onecol');
    $assert_session->pageTextContains('Layout Builder Layout: layout_onecol');
  }

  /**
   * Tests the functionality of custom section labels.
   */
  public function testSectionLabels() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $page->checkField('layout[enabled]');
    $page->pressButton('Save');
    $page->checkField('layout[allow_custom]');
    $page->pressButton('Save');

    $this->drupalGet('node/1/layout');
    $page->clickLink('Add section');
    $page->clickLink('One column');
    $page->fillField('layout_settings[label]', 'My Cool Section');
    $page->pressButton('Add section');
    $assert_session->pageTextContains('My Cool Section');
    $page->pressButton('Save layout');
    $assert_session->pageTextNotContains('My Cool Section');
  }

  /**
   * Tests that layouts can be context-aware.
   */
  public function testContextAwareLayouts() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $account = $this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]);
    $this->drupalLogin($account);

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('Layout Builder Test: Context Aware');
    $page->pressButton('Add section');
    // See \Drupal\layout_builder_test\Plugin\Layout\TestContextAwareLayout::build().
    $assert_session->elementExists('css', '.user--' . $account->getAccountName());
    $page->clickLink('Configure Section 1');
    $page->fillField('layout_settings[label]', 'My section');
    $page->pressButton('Update');
    $assert_session->linkExists('Configure My section');
    $page->clickLink('Add block');
    $page->clickLink('Powered by Drupal');
    $page->pressButton('Add block');
    $page->pressButton('Save layout');
    $this->drupalGet('node/1');
    // See \Drupal\layout_builder_test\Plugin\Layout\TestContextAwareLayout::build().
    $assert_session->elementExists('css', '.user--' . $account->getAccountName());
  }

  /**
   * Tests that sections can provide custom attributes.
   */
  public function testCustomSectionAttributes() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $page->clickLink('Manage layout');
    $page->clickLink('Add section');
    $page->clickLink('Layout Builder Test Plugin');
    $page->pressButton('Add section');
    // See \Drupal\layout_builder_test\Plugin\Layout\LayoutBuilderTestPlugin::build().
    $assert_session->elementExists('css', '.go-birds');
  }

  /**
   * Tests the usage of placeholders for empty blocks.
   *
   * @see \Drupal\Core\Render\PreviewFallbackInterface::getPreviewFallbackString()
   * @see \Drupal\layout_builder\EventSubscriber\BlockComponentRenderArray::onBuildRender()
   */
  public function testBlockPlaceholder() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');

    // Customize the default view mode.
    $this->drupalGet("$field_ui_prefix/display/default/layout");

    // Add a block whose content is controlled by state and is empty by default.
    $this->clickLink('Add block');
    $this->clickLink('Test block caching');
    $page->fillField('settings[label]', 'The block label');
    $page->pressButton('Add block');

    $block_content = 'I am content';
    $placeholder_content = 'Placeholder for the "The block label" block';

    // The block placeholder is displayed and there is no content.
    $assert_session->pageTextContains($placeholder_content);
    $assert_session->pageTextNotContains($block_content);

    // Set block content and reload the page.
    \Drupal::state()->set('block_test.content', $block_content);
    $this->getSession()->reload();

    // The block placeholder is no longer displayed and the content is visible.
    $assert_session->pageTextNotContains($placeholder_content);
    $assert_session->pageTextContains($block_content);
  }

  /**
   * Tests the ability to use a specified block label for field blocks.
   */
  public function testFieldBlockLabel() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    $this->drupalGet("$field_ui_prefix/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');

    // Customize the default view mode.
    $this->drupalGet("$field_ui_prefix/display/default/layout");

    // Add a body block whose label will be overridden.
    $this->clickLink('Add block');
    $this->clickLink('Body');

    // Enable the Label Display and set the Label to a modified field
    // block label.
    $modified_field_block_label = 'Modified Field Block Label';
    $page->checkField('settings[label_display]');
    $page->fillField('settings[label]', $modified_field_block_label);

    // Save the block and layout.
    $page->pressButton('Add block');
    $page->pressButton('Save layout');

    // Revisit the default layout view mode page.
    $this->drupalGet("$field_ui_prefix/display/default/layout");

    // The modified field block label is displayed.
    $assert_session->pageTextContains($modified_field_block_label);
  }

  /**
   * Tests a custom alter of the overrides form.
   */
  public function testOverridesFormAlter() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer nodes',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Enable overrides.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    $this->drupalGet('node/1');

    // The status checkbox should be checked by default.
    $page->clickLink('Layout');
    $assert_session->checkboxChecked('status[value]');
    $page->pressButton('Save layout');
    $assert_session->pageTextContains('The layout override has been saved.');

    // Unchecking the status checkbox will unpublish the entity.
    $page->clickLink('Layout');
    $page->uncheckField('status[value]');
    $page->pressButton('Save layout');
    $assert_session->statusCodeEquals(403);
    $assert_session->pageTextContains('The layout override has been saved.');
  }

  /**
   * Tests the Block UI when Layout Builder is installed.
   */
  public function testBlockUiListing() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'administer blocks',
    ]));

    $this->drupalGet('admin/structure/block');
    $page->clickLink('Place block');

    // Ensure that blocks expected to appear are available.
    $assert_session->pageTextContains('Test HTML block');
    $assert_session->pageTextContains('Block test');
    // Ensure that blocks not expected to appear are not available.
    $assert_session->pageTextNotContains('Body');
    $assert_session->pageTextNotContains('Content fields');
  }

  /**
   * Tests the expected breadcrumbs of the Layout Builder UI.
   */
  public function testBreadcrumb() {
    $page = $this->getSession()->getPage();

    $this->drupalPlaceBlock('system_breadcrumb_block');

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer content types',
      'access administration pages',
    ]));

    // From the manage display page, go to manage the layout.
    $this->drupalGet('admin/structure/types/manage/bundle_with_section_field/display/default');
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');
    $page->clickLink('Manage layout');

    $breadcrumb_titles = [];
    foreach ($page->findAll('css', '.breadcrumb a') as $link) {
      $breadcrumb_titles[$link->getText()] = $link->getAttribute('href');
    }
    $base_path = base_path();
    $expected = [
      'Home' => $base_path,
      'Administration' => $base_path . 'admin',
      'Structure' => $base_path . 'admin/structure',
      'Content types' => $base_path . 'admin/structure/types',
      'Bundle with section field' => $base_path . 'admin/structure/types/manage/bundle_with_section_field',
      'Manage display' => $base_path . 'admin/structure/types/manage/bundle_with_section_field/display/default',
      'External link' => 'http://www.example.com',
    ];
    $this->assertSame($expected, $breadcrumb_titles);
  }

  /**
   * Tests a config-based implementation of Layout Builder.
   *
   * @see \Drupal\layout_builder_test\Plugin\SectionStorage\SimpleConfigSectionStorage
   */
  public function testSimpleConfigBasedLayout() {
    $assert_session = $this->assertSession();

    $this->drupalLogin($this->createUser(['configure any layout']));

    // Prepare an object with a pre-existing section.
    $this->container->get('config.factory')->getEditable('layout_builder_test.test_simple_config.existing')
      ->set('sections', [(new Section('layout_twocol'))->toArray()])
      ->save();

    // The pre-existing section is found.
    $this->drupalGet('layout-builder-test-simple-config/existing');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->elementsCount('css', '.layout--twocol', 1);

    // No layout is selected for a new object.
    $this->drupalGet('layout-builder-test-simple-config/new');
    $assert_session->elementNotExists('css', '.layout');
  }

  /**
   * Tests removing all sections from overrides and defaults.
   */
  public function testRemovingAllSections() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    // Enable overrides.
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->drupalGet("{$field_ui_prefix}/display/default");
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    // By default, there is one section.
    $this->drupalGet('node/1');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->pageTextContains('The first node body');

    $page->clickLink('Layout');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 2);

    // Remove the only section from the override.
    $page->clickLink('Remove Section 1');
    $page->pressButton('Remove');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 1);

    // The override is still used instead of the default, despite being empty.
    $page->pressButton('Save layout');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->pageTextNotContains('The first node body');

    $page->clickLink('Layout');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 1);

    // Add one section to the override.
    $page->clickLink('Add section');
    $page->clickLink('One column');
    $page->pressButton('Add section');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 2);

    $page->pressButton('Save layout');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->pageTextNotContains('The first node body');

    // By default, the default has one section.
    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 2);

    // Remove the only section from the default.
    $page->clickLink('Remove Section 1');
    $page->pressButton('Remove');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 1);

    $page->pressButton('Save layout');
    $page->clickLink('Manage layout');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 0);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 1);

    // The override is still in use.
    $this->drupalGet('node/1');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->pageTextNotContains('The first node body');
    $page->clickLink('Layout');
    $assert_session->elementsCount('css', '.layout', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-block', 1);
    $assert_session->elementsCount('css', '.layout-builder__add-section', 2);

    // Revert the override.
    $page->pressButton('Revert to defaults');
    $page->pressButton('Revert');
    $assert_session->elementsCount('css', '.layout', 0);
    $assert_session->pageTextNotContains('The first node body');
  }

  /**
   * Tests removing section without layout label configuration.
   */
  public function testRemovingSectionWithoutLayoutLabel() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));

    // Enable overrides.
    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    $this->drupalGet("$field_ui_prefix/display/default");
    $this->submitForm(['layout[enabled]' => TRUE], 'Save');
    $this->submitForm(['layout[allow_custom]' => TRUE], 'Save');

    $this->drupalGet("$field_ui_prefix/display/default/layout");
    $page->clickLink('Add section');

    $assert_session->linkExists('Layout Without Label');
    $page->clickLink('Layout Without Label');
    $page->pressButton('Add section');
    $assert_session->elementsCount('css', '.layout', 2);

    $assert_session->linkExists('Remove Section 1');
    $this->clickLink('Remove Section 1');
    $page->pressButton('Remove');

    $assert_session->statusCodeEquals(200);
    $assert_session->elementsCount('css', '.layout', 1);
  }

  /**
   * Asserts that the correct layouts are available.
   *
   * @internal
   */
  protected function assertCorrectLayouts(): void {
    $assert_session = $this->assertSession();
    // Ensure the layouts provided by layout_builder are available.
    $expected_layouts_hrefs = [
      'layout_builder/configure/section/overrides/node.1/0/layout_onecol',
      'layout_builder/configure/section/overrides/node.1/0/layout_twocol_section',
      'layout_builder/configure/section/overrides/node.1/0/layout_threecol_section',
      'layout_builder/configure/section/overrides/node.1/0/layout_fourcol_section',
    ];
    foreach ($expected_layouts_hrefs as $expected_layouts_href) {
      $assert_session->linkByHrefExists($expected_layouts_href);
    }
    // Ensure the layout_discovery module's layouts were removed.
    $unexpected_layouts = [
      'twocol',
      'twocol_bricks',
      'threecol_25_50_25',
      'threecol_33_34_33',
    ];
    foreach ($unexpected_layouts as $unexpected_layout) {
      $assert_session->linkByHrefNotExists("layout_builder/add/section/overrides/node.1/0/$unexpected_layout");
      $assert_session->linkByHrefNotExists("layout_builder/configure/section/overrides/node.1/0/$unexpected_layout");
    }
  }

  /**
   * Tests the Layout Builder UI with a context defined at runtime.
   */
  public function testLayoutBuilderContexts() {
    $node_url = 'node/1';

    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
    ]));
    $field_ui_prefix = 'admin/structure/types/manage/bundle_with_section_field';
    $this->drupalGet("$field_ui_prefix/display/default");
    $this->submitForm([
      'layout[enabled]' => TRUE,
    ], 'Save');

    $this->drupalGet("$field_ui_prefix/display/default");
    $this->submitForm([
      'layout[allow_custom]' => TRUE,
    ], 'Save');

    $this->drupalGet($node_url);
    $assert_session->linkExists('Layout');
    $this->clickLink('Layout');
    $assert_session->linkExists('Add section');

    // Add the testing block.
    $page->clickLink('Add block');
    $this->clickLink('Can I have runtime contexts');
    $page->pressButton('Add block');

    // Ensure the runtime context value is rendered before saving.
    $assert_session->pageTextContains('for sure you can');

    // Save the layout, and test that the value is rendered after save.
    $page->pressButton('Save layout');
    $assert_session->addressEquals($node_url);
    $assert_session->pageTextContains('for sure you can');
    $assert_session->elementExists('css', '.layout');
  }

}
