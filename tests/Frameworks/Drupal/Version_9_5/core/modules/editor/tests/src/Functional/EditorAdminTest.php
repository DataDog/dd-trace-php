<?php

namespace Drupal\Tests\editor\Functional;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\filter\Entity\FilterFormat;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests administration of text editors.
 *
 * @group editor
 */
class EditorAdminTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['filter', 'editor'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with the 'administer filters' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add text format.
    $filtered_html_format = FilterFormat::create([
      'format' => 'filtered_html',
      'name' => 'Filtered HTML',
      'weight' => 0,
      'filters' => [],
    ]);
    $filtered_html_format->save();

    // Create admin user.
    $this->adminUser = $this->drupalCreateUser(['administer filters']);
  }

  /**
   * Tests an existing format without any editors available.
   */
  public function testNoEditorAvailable() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/formats/manage/filtered_html');

    // Ensure the form field order is correct.
    $raw_content = $this->getSession()->getPage()->getContent();
    $roles_pos = strpos($raw_content, 'Roles');
    $editor_pos = strpos($raw_content, 'Text editor');
    $filters_pos = strpos($raw_content, 'Enabled filters');
    $this->assertGreaterThan($roles_pos, $editor_pos);
    $this->assertLessThan($filters_pos, $editor_pos);

    // Verify the <select>.
    $select = $this->assertSession()->selectExists('editor[editor]');
    $this->assertSame('disabled', $select->getAttribute('disabled'));
    $options = $select->findAll('css', 'option');
    $this->assertCount(1, $options);
    $this->assertSame('None', $options[0]->getText(), 'Option 1 in the Text Editor select is "None".');
    $this->assertSession()->pageTextContains('This option is disabled because no modules that provide a text editor are currently enabled.');
  }

  /**
   * Tests adding a text editor to an existing text format.
   */
  public function testAddEditorToExistingFormat() {
    $this->enableUnicornEditor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/formats/manage/filtered_html');
    $edit = $this->selectUnicornEditor();
    // Configure Unicorn Editor's setting to another value.
    $edit['editor[settings][ponies_too]'] = FALSE;
    $this->submitForm($edit, 'Save configuration');
    $this->verifyUnicornEditorConfiguration('filtered_html', FALSE);

    // Switch back to 'None' and check the Unicorn Editor's settings are gone.
    $edit = [
      'editor[editor]' => '',
    ];
    $this->submitForm($edit, 'Configure');
    $this->assertSession()->fieldNotExists('editor[settings][ponies_too]');
  }

  /**
   * Tests adding a text editor to a new text format.
   */
  public function testAddEditorToNewFormat() {
    $this->addEditorToNewFormat('monoceros', 'Monoceros');
    $this->verifyUnicornEditorConfiguration('monoceros');
  }

  /**
   * Tests format disabling.
   */
  public function testDisableFormatWithEditor() {
    $formats = ['monoceros' => 'Monoceros', 'tattoo' => 'Tattoo'];

    // Install the node module.
    $this->container->get('module_installer')->install(['node']);
    $this->resetAll();
    // Create a new node type and attach the 'body' field to it.
    $node_type = NodeType::create(['type' => mb_strtolower($this->randomMachineName()), 'name' => $this->randomString()]);
    $node_type->save();
    node_add_body_field($node_type, $this->randomString());

    $permissions = ['administer filters', "edit any {$node_type->id()} content"];
    foreach ($formats as $format => $name) {
      // Create a format and add an editor to this format.
      $this->addEditorToNewFormat($format, $name);
      // Add permission for this format.
      $permissions[] = "use text format $format";
    }

    // Create a node having the body format value 'monoceros'.
    $node = Node::create([
      'type' => $node_type->id(),
      'title' => $this->randomString(),
    ]);
    $node->body->value = $this->randomString(100);
    $node->body->format = 'monoceros';
    $node->save();

    // Log in as a user able to use both formats and edit nodes of created type.
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // The node edit page header.
    $text = (string) new FormattableMarkup('<em>Edit @type</em> @title', ['@type' => $node_type->label(), '@title' => $node->label()]);

    // Go to node edit form.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->responseContains($text);

    // Disable the format assigned to the 'body' field of the node.
    FilterFormat::load('monoceros')->disable()->save();

    // Edit again the node.
    $this->drupalGet('node/' . $node->id() . '/edit');
    $this->assertSession()->responseContains($text);
  }

  /**
   * Tests switching text editor to none does not throw a TypeError.
   */
  public function testSwitchEditorToNone() {
    $this->enableUnicornEditor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/formats/manage/filtered_html');
    $edit = $this->selectUnicornEditor();

    // Switch editor to 'None'.
    $edit = [
      'editor[editor]' => '',
    ];
    $this->submitForm($edit, 'Configure');
    $this->submitForm($edit, 'Save configuration');
  }

  /**
   * Adds an editor to a new format using the UI.
   *
   * @param string $format_id
   *   The format id.
   * @param string $format_name
   *   The format name.
   */
  protected function addEditorToNewFormat($format_id, $format_name) {
    $this->enableUnicornEditor();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/content/formats/add');
    // Configure the text format name.
    $edit = [
      'name' => $format_name,
      'format' => $format_id,
    ];
    $edit += $this->selectUnicornEditor();
    $this->submitForm($edit, 'Save configuration');
  }

  /**
   * Enables the unicorn editor.
   */
  protected function enableUnicornEditor() {
    if (!$this->container->get('module_handler')->moduleExists('editor_test')) {
      $this->container->get('module_installer')->install(['editor_test']);
    }
  }

  /**
   * Tests and selects the unicorn editor.
   *
   * @return array
   *   Returns an edit array containing the values to be posted.
   */
  protected function selectUnicornEditor() {
    // Verify the <select> when a text editor is available.
    $select = $this->assertSession()->selectExists('editor[editor]');
    $this->assertFalse($select->hasAttribute('disabled'));
    $options = $select->findAll('css', 'option');
    $this->assertCount(2, $options);
    $this->assertSame('None', $options[0]->getText(), 'Option 1 in the Text Editor select is "None".');
    $this->assertSame('Unicorn Editor', $options[1]->getText(), 'Option 2 in the Text Editor select is "Unicorn Editor".');
    $this->assertTrue($options[0]->hasAttribute('selected'), 'Option 1 ("None") is selected.');
    // Ensure the none option is selected.
    $this->assertSession()->pageTextNotContains('This option is disabled because no modules that provide a text editor are currently enabled.');

    // Select the "Unicorn Editor" editor and click the "Configure" button.
    $edit = [
      'editor[editor]' => 'unicorn',
    ];
    $this->submitForm($edit, 'Configure');
    $this->assertSession()->checkboxChecked('editor[settings][ponies_too]');

    return $edit;
  }

  /**
   * Verifies unicorn editor configuration.
   *
   * @param string $format_id
   *   The format machine name.
   * @param bool $ponies_too
   *   The expected value of the ponies_too setting.
   */
  protected function verifyUnicornEditorConfiguration($format_id, $ponies_too = TRUE) {
    $editor = editor_load($format_id);
    $settings = $editor->getSettings();
    $this->assertSame('unicorn', $editor->getEditor(), 'The text editor is configured correctly.');
    $this->assertSame($ponies_too, $settings['ponies_too'], 'The text editor settings are stored correctly.');
    $this->drupalGet('admin/config/content/formats/manage/' . $format_id);
    $select = $this->assertSession()->selectExists('editor[editor]');
    $this->assertFalse($select->hasAttribute('disabled'));
    $options = $select->findAll('css', 'option');
    $this->assertCount(2, $options);
    $this->assertTrue($options[1]->isSelected(), 'Option 2 ("Unicorn Editor") is selected.');
  }

}
