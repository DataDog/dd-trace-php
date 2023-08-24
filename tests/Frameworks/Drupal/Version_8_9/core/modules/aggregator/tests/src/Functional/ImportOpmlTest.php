<?php

namespace Drupal\Tests\aggregator\Functional;

use Drupal\aggregator\Entity\Feed;

/**
 * Tests OPML import.
 *
 * @group aggregator
 */
class ImportOpmlTest extends AggregatorTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = ['block', 'help'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $admin_user = $this->drupalCreateUser([
      'administer news feeds',
      'access news feeds',
      'create article content',
      'administer blocks',
    ]);
    $this->drupalLogin($admin_user);
  }

  /**
   * Opens OPML import form.
   */
  public function openImportForm() {
    // Enable the help block.
    $this->drupalPlaceBlock('help_block', ['region' => 'help']);

    $this->drupalGet('admin/config/services/aggregator/add/opml');
    $this->assertText('A single OPML document may contain many feeds.', 'Found OPML help text.');
    $this->assertField('files[upload]', 'Found file upload field.');
    $this->assertField('remote', 'Found Remote URL field.');
    $this->assertField('refresh', '', 'Found Refresh field.');
  }

  /**
   * Submits form filled with invalid fields.
   */
  public function validateImportFormFields() {
    $count_query = \Drupal::entityQuery('aggregator_feed')->count();
    $before = $count_query->execute();

    $edit = [];
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $edit, t('Import'));
    $this->assertRaw(t('<em>Either</em> upload a file or enter a URL.'), 'Error if no fields are filled.');

    $path = $this->getEmptyOpml();
    $edit = [
      'files[upload]' => $path,
      'remote' => file_create_url($path),
    ];
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $edit, t('Import'));
    $this->assertRaw(t('<em>Either</em> upload a file or enter a URL.'), 'Error if both fields are filled.');

    $edit = ['remote' => 'invalidUrl://empty'];
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $edit, t('Import'));
    $this->assertText(t('The URL invalidUrl://empty is not valid.'), 'Error if the URL is invalid.');

    $after = $count_query->execute();
    $this->assertEqual($before, $after, 'No feeds were added during the three last form submissions.');
  }

  /**
   * Submits form with invalid, empty, and valid OPML files.
   */
  protected function submitImportForm() {
    $count_query = \Drupal::entityQuery('aggregator_feed')->count();
    $before = $count_query->execute();

    $form['files[upload]'] = $this->getInvalidOpml();
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $form, t('Import'));
    $this->assertText(t('No new feed has been added.'), 'Attempting to upload invalid XML.');

    $edit = ['remote' => file_create_url($this->getEmptyOpml())];
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $edit, t('Import'));
    $this->assertText(t('No new feed has been added.'), 'Attempting to load empty OPML from remote URL.');

    $after = $count_query->execute();
    $this->assertEqual($before, $after, 'No feeds were added during the two last form submissions.');

    foreach (Feed::loadMultiple() as $feed) {
      $feed->delete();
    }

    $feeds[0] = $this->getFeedEditArray();
    $feeds[1] = $this->getFeedEditArray();
    $feeds[2] = $this->getFeedEditArray();
    $edit = [
      'files[upload]' => $this->getValidOpml($feeds),
      'refresh'       => '900',
    ];
    $this->drupalPostForm('admin/config/services/aggregator/add/opml', $edit, t('Import'));
    $this->assertRaw(t('A feed with the URL %url already exists.', ['%url' => $feeds[0]['url[0][value]']]), 'Verifying that a duplicate URL was identified');
    $this->assertRaw(t('A feed named %title already exists.', ['%title' => $feeds[1]['title[0][value]']]), 'Verifying that a duplicate title was identified');

    $after = $count_query->execute();
    $this->assertEqual($after, 2, 'Verifying that two distinct feeds were added.');

    $feed_entities = Feed::loadMultiple();
    $refresh = TRUE;
    foreach ($feed_entities as $feed_entity) {
      $title[$feed_entity->getUrl()] = $feed_entity->label();
      $url[$feed_entity->label()] = $feed_entity->getUrl();
      $refresh = $refresh && $feed_entity->getRefreshRate() == 900;
    }

    $this->assertEqual($title[$feeds[0]['url[0][value]']], $feeds[0]['title[0][value]'], 'First feed was added correctly.');
    $this->assertEqual($url[$feeds[1]['title[0][value]']], $feeds[1]['url[0][value]'], 'Second feed was added correctly.');
    $this->assertTrue($refresh, 'Refresh times are correct.');
  }

  /**
   * Tests the import of an OPML file.
   */
  public function testOpmlImport() {
    $this->openImportForm();
    $this->validateImportFormFields();
    $this->submitImportForm();
  }

}
