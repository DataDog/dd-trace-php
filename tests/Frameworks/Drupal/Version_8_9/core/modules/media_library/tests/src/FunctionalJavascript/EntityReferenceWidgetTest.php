<?php

namespace Drupal\Tests\media_library\FunctionalJavascript;

/**
 * Tests the Media library entity reference widget.
 *
 * @group media_library
 */
class EntityReferenceWidgetTest extends MediaLibraryTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field_ui'];

  /**
   * Test media items.
   *
   * @var \Drupal\media\MediaInterface[]
   */
  protected $mediaItems = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create a few example media items for use in selection.
    $this->mediaItems = $this->createMediaItems([
      'type_one' => [
        'Horse',
        'Bear',
        'Cat',
        'Dog',
      ],
      'type_two' => [
        'Crocodile',
        'Lizard',
        'Snake',
        'Turtle',
      ],
    ]);

    // Create a user who can use the Media library.
    $user = $this->drupalCreateUser([
      'access content',
      'create basic_page content',
      'edit own basic_page content',
      'view media',
      'create media',
      'administer node form display',
    ]);
    $this->drupalLogin($user);
  }

  /**
   * Tests that disabled media items don't capture focus on page load.
   */
  public function testFocusNotAppliedWithoutSelectionChange() {
    // Create a node with the maximum number of values for the field_twin_media
    // field.
    $node = $this->drupalCreateNode([
      'type' => 'basic_page',
      'field_twin_media' => [
        $this->mediaItems['Horse'],
        $this->mediaItems['Bear'],
      ],
    ]);
    $this->drupalGet($node->toUrl('edit-form'));
    $open_button = $this->assertElementExistsAfterWait('css', '.js-media-library-open-button[name^="field_twin_media"]');
    // The open button should be disabled, but not have the
    // 'data-disabled-focus' attribute.
    $this->assertFalse($open_button->hasAttribute('data-disabled-focus'));
    $this->assertTrue($open_button->hasAttribute('disabled'));
    // The button should be disabled.
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":disabled")');
    // The button should not have focus.
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").not(":focus")');
  }

  /**
   * Tests that the Media library's widget works as expected.
   */
  public function testWidget() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    // Visit a node create page.
    $this->drupalGet('node/add/basic_page');

    // Assert that media widget instances are present.
    $assert_session->pageTextContains('Unlimited media');
    $assert_session->pageTextContains('Twin media');
    $assert_session->pageTextContains('Single media type');
    $assert_session->pageTextContains('Empty types media');

    // Assert generic media library elements.
    $this->openMediaLibraryForField('field_unlimited_media');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert that the media type menu is available when more than 1 type is
    // configured for the field.
    $menu = $this->openMediaLibraryForField('field_unlimited_media');
    $this->assertTrue($menu->hasLink('Show Type One media (selected)'));
    $this->assertFalse($menu->hasLink('Type Two'));
    $this->assertTrue($menu->hasLink('Type Three'));
    $this->assertFalse($menu->hasLink('Type Four'));
    $this->switchToMediaType('Three');
    // Assert the active tab is set correctly.
    $this->assertFalse($menu->hasLink('Show Type One media (selected)'));
    $this->assertTrue($menu->hasLink('Show Type Three media (selected)'));
    // Assert the focus is set to the first tabbable element when a vertical tab
    // is clicked.
    $this->assertJsCondition('jQuery("#media-library-content :tabbable:first").is(":focus")');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert that there are no links in the media library view.
    $this->openMediaLibraryForField('field_unlimited_media');
    $assert_session->elementNotExists('css', '.media-library-item__name a');
    $assert_session->elementNotExists('css', '.view-media-library .media-library-item__edit');
    $assert_session->elementNotExists('css', '.view-media-library .media-library-item__remove');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert that the media type menu is available when the target_bundles
    // setting for the entity reference field is null. All types should be
    // allowed in this case.
    $menu = $this->openMediaLibraryForField('field_null_types_media');

    // Assert that the button to open the media library does not submit the
    // parent form. We can do this by checking if the validation of the parent
    // form is not triggered.
    $assert_session->pageTextNotContains('Title field is required.');

    $this->assertTrue($menu->hasLink('Type One'));
    $this->assertTrue($menu->hasLink('Type Two'));
    $this->assertTrue($menu->hasLink('Type Three'));
    $this->assertTrue($menu->hasLink('Type Four'));
    $this->assertTrue($menu->hasLink('Type Five'));
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert that the media type menu is not available when only 1 type is
    // configured for the field.
    $this->openMediaLibraryForField('field_single_media_type', '#media-library-wrapper');
    $this->waitForElementTextContains('.media-library-selected-count', '0 of 1 item selected');

    // Select a media item, assert the hidden selection field contains the ID of
    // the selected item.
    $this->selectMediaItem(0);
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', '4');
    $this->assertSelectedMediaCount('1 of 1 item selected');
    $assert_session->elementNotExists('css', '.js-media-library-menu');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert the menu links can be sorted through the widget configuration.
    $this->openMediaLibraryForField('field_twin_media');
    $links = $this->getTypesMenu()->findAll('css', 'a');
    $link_titles = [];
    foreach ($links as $link) {
      $link_titles[] = $link->getText();
    }
    $expected_link_titles = ['Show Type Three media (selected)', 'Show Type One media', 'Show Type Two media', 'Show Type Four media'];
    $this->assertSame($link_titles, $expected_link_titles);
    $this->drupalGet('admin/structure/types/manage/basic_page/form-display');
    $assert_session->buttonExists('field_twin_media_settings_edit')->press();
    $this->assertElementExistsAfterWait('css', '#field-twin-media .tabledrag-toggle-weight')->press();
    $assert_session->fieldExists('fields[field_twin_media][settings_edit_form][settings][media_types][type_one][weight]')->selectOption(0);
    $assert_session->fieldExists('fields[field_twin_media][settings_edit_form][settings][media_types][type_three][weight]')->selectOption(1);
    $assert_session->fieldExists('fields[field_twin_media][settings_edit_form][settings][media_types][type_four][weight]')->selectOption(2);
    $assert_session->fieldExists('fields[field_twin_media][settings_edit_form][settings][media_types][type_two][weight]')->selectOption(3);
    $assert_session->buttonExists('Save')->press();

    $this->drupalGet('node/add/basic_page');
    $this->openMediaLibraryForField('field_twin_media');
    $link_titles = array_map(function ($link) {
      return $link->getText();
    }, $links);
    $this->assertSame($link_titles, ['Show Type One media (selected)', 'Show Type Three media', 'Show Type Four media', 'Show Type Two media']);
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert the announcements for media type navigation in the media library.
    $this->openMediaLibraryForField('field_unlimited_media');
    $this->switchToMediaType('Three');
    $this->assertNotEmpty($assert_session->waitForText('Showing Type Three media.'));
    $this->switchToMediaType('One');
    $this->assertNotEmpty($assert_session->waitForText('Showing Type One media.'));
    // Assert the links can be triggered by via the spacebar.
    $assert_session->elementExists('named', ['link', 'Type Three'])->keyPress(32);
    $this->waitForText('Showing Type Three media.');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert media is only visible on the tab for the related media type.
    $this->openMediaLibraryForField('field_unlimited_media');
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextNotContains('Turtle');
    $this->switchToMediaType('Three');
    $this->assertNotEmpty($assert_session->waitForText('Showing Type Three media.'));
    $assert_session->elementExists('named', ['link', 'Show Type Three media (selected)']);
    $assert_session->pageTextNotContains('Dog');
    $assert_session->pageTextNotContains('Bear');
    $assert_session->pageTextNotContains('Turtle');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert the exposed name filter of the view.
    $this->openMediaLibraryForField('field_unlimited_media');
    $session = $this->getSession();
    $session->getPage()->fillField('Name', 'Dog');
    $session->getPage()->pressButton('Apply filters');
    $this->waitForText('Dog');
    $this->waitForNoText('Bear');
    $session->getPage()->fillField('Name', '');
    $session->getPage()->pressButton('Apply filters');
    $this->waitForText('Dog');
    $this->waitForText('Bear');
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Assert adding a single media item and removing it.
    $this->openMediaLibraryForField('field_twin_media');
    $this->selectMediaItem(0);
    $this->pressInsertSelected('Added one media item.');
    // Assert the focus is set back on the open button of the media field.
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":focus")');

    // Assert that we can toggle the visibility of the weight inputs.
    $wrapper = $assert_session->elementExists('css', '.field--name-field-twin-media');
    $wrapper->pressButton('Show media item weights');
    $assert_session->fieldExists('Weight', $wrapper)->click();
    $wrapper->pressButton('Hide media item weights');

    // Remove the selected item.
    $button = $assert_session->buttonExists('Remove', $wrapper);
    $this->assertSame('Remove Dog', $button->getAttribute('aria-label'));
    $button->press();
    $this->waitForText('Dog has been removed.');
    // Assert the focus is set back on the open button of the media field.
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":focus")');

    // Assert we can select the same media item twice.
    $this->openMediaLibraryForField('field_twin_media');
    $page->checkField('Select Dog');
    $this->pressInsertSelected('Added one media item.');
    $this->openMediaLibraryForField('field_twin_media');
    $page->checkField('Select Dog');
    $this->pressInsertSelected('Added one media item.');

    // Assert the same has been added twice and remove the items again.
    $this->waitForElementsCount('css', '.field--name-field-twin-media [data-media-library-item-delta]', 2);
    $assert_session->hiddenFieldValueEquals('field_twin_media[selection][0][target_id]', 4);
    $assert_session->hiddenFieldValueEquals('field_twin_media[selection][1][target_id]', 4);
    $wrapper->pressButton('Remove');
    $this->waitForText('Dog has been removed.');
    $wrapper->pressButton('Remove');
    $this->waitForText('Dog has been removed.');
    $result = $wrapper->waitFor(10, function ($wrapper) {
      /** @var \Behat\Mink\Element\NodeElement $wrapper */
      return $wrapper->findButton('Remove') == NULL;
    });
    $this->assertTrue($result);

    // Assert the selection is persistent in the media library modal, and
    // the number of selected items is displayed correctly.
    $this->openMediaLibraryForField('field_twin_media');
    // Assert the number of selected items is displayed correctly.
    $this->assertSelectedMediaCount('0 of 2 items selected');
    // Select a media item, assert the hidden selection field contains the ID of
    // the selected item.
    $checkboxes = $this->getCheckboxes();
    $this->assertCount(4, $checkboxes);
    $this->selectMediaItem(0, '1 of 2 items selected');
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', '4');
    // Select another item and assert the number of selected items is updated.
    $this->selectMediaItem(1, '2 of 2 items selected');
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', '4,3');
    // Assert unselected items are disabled when the maximum allowed items are
    // selected (cardinality for this field is 2).
    $this->assertTrue($checkboxes[2]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[3]->hasAttribute('disabled'));
    // Assert the selected items are updated when deselecting an item.
    $checkboxes[0]->click();
    $this->assertSelectedMediaCount('1 of 2 items selected');
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', '3');
    // Assert deselected items are available again.
    $this->assertFalse($checkboxes[2]->hasAttribute('disabled'));
    $this->assertFalse($checkboxes[3]->hasAttribute('disabled'));
    // The selection should be persisted when navigating to other media types in
    // the modal.
    $this->switchToMediaType('Three');
    $this->switchToMediaType('One');
    $selected_checkboxes = [];
    foreach ($this->getCheckboxes() as $checkbox) {
      if ($checkbox->isChecked()) {
        $selected_checkboxes[] = $checkbox->getValue();
      }
    }
    $this->assertCount(1, $selected_checkboxes);
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', implode(',', $selected_checkboxes));
    $this->assertSelectedMediaCount('1 of 2 items selected');
    // Add to selection from another type.
    $this->switchToMediaType('Two');
    $checkboxes = $this->getCheckboxes();
    $this->assertCount(4, $checkboxes);
    $this->selectMediaItem(0, '2 of 2 items selected');
    $assert_session->hiddenFieldValueEquals('media-library-modal-selection', '3,8');
    // Assert unselected items are disabled when the maximum allowed items are
    // selected (cardinality for this field is 2).
    $this->assertFalse($checkboxes[0]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[1]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[2]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[3]->hasAttribute('disabled'));
    // Assert the checkboxes are also disabled on other pages.
    $this->switchToMediaType('One');
    $this->assertTrue($checkboxes[0]->hasAttribute('disabled'));
    $this->assertFalse($checkboxes[1]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[2]->hasAttribute('disabled'));
    $this->assertTrue($checkboxes[3]->hasAttribute('disabled'));
    // Select the items.
    $this->pressInsertSelected('Added 2 media items.');
    // Assert the open button is disabled.
    $open_button = $this->assertElementExistsAfterWait('css', '.js-media-library-open-button[name^="field_twin_media"]');
    $this->assertTrue($open_button->hasAttribute('data-disabled-focus'));
    $this->assertTrue($open_button->hasAttribute('disabled'));
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":disabled")');

    // Ensure that the selection completed successfully.
    $assert_session->pageTextNotContains('Add or select media');
    $assert_session->elementTextNotContains('css', '#field_twin_media-media-library-wrapper', 'Dog');
    $assert_session->elementTextContains('css', '#field_twin_media-media-library-wrapper', 'Cat');
    $assert_session->elementTextContains('css', '#field_twin_media-media-library-wrapper', 'Turtle');
    $assert_session->elementTextNotContains('css', '#field_twin_media-media-library-wrapper', 'Snake');

    // Remove "Cat" (happens to be the first remove button on the page).
    $button = $assert_session->buttonExists('Remove', $wrapper);
    $this->assertSame('Remove Cat', $button->getAttribute('aria-label'));
    $button->press();
    $this->waitForText('Cat has been removed.');
    // Assert the focus is set to the wrapper of the other selected item.
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper [data-media-library-item-delta]").is(":focus")');
    $assert_session->elementTextNotContains('css', '#field_twin_media-media-library-wrapper', 'Cat');
    $assert_session->elementTextContains('css', '#field_twin_media-media-library-wrapper', 'Turtle');
    // Assert the open button is no longer disabled.
    $open_button = $assert_session->elementExists('css', '.js-media-library-open-button[name^="field_twin_media"]');
    $this->assertFalse($open_button->hasAttribute('data-disabled-focus'));
    $this->assertFalse($open_button->hasAttribute('disabled'));
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":not(:disabled)")');

    // Open the media library again and select another item.
    $this->openMediaLibraryForField('field_twin_media');
    $this->selectMediaItem(0);
    $this->pressInsertSelected('Added one media item.');
    $this->waitForElementTextContains('#field_twin_media-media-library-wrapper', 'Dog');
    $assert_session->elementTextNotContains('css', '#field_twin_media-media-library-wrapper', 'Cat');
    $assert_session->elementTextContains('css', '#field_twin_media-media-library-wrapper', 'Turtle');
    $assert_session->elementTextNotContains('css', '#field_twin_media-media-library-wrapper', 'Snake');
    // Assert the open button is disabled.
    $this->assertTrue($assert_session->elementExists('css', '.js-media-library-open-button[name^="field_twin_media"]')->hasAttribute('data-disabled-focus'));
    $this->assertTrue($assert_session->elementExists('css', '.js-media-library-open-button[name^="field_twin_media"]')->hasAttribute('disabled'));
    $this->assertJsCondition('jQuery("#field_twin_media-media-library-wrapper .js-media-library-open-button").is(":disabled")');

    // Assert the selection is cleared when the modal is closed.
    $this->openMediaLibraryForField('field_unlimited_media');
    $checkboxes = $this->getCheckboxes();
    $this->assertGreaterThanOrEqual(4, count($checkboxes));
    // Nothing is selected yet.
    $this->assertFalse($checkboxes[0]->isChecked());
    $this->assertFalse($checkboxes[1]->isChecked());
    $this->assertFalse($checkboxes[2]->isChecked());
    $this->assertFalse($checkboxes[3]->isChecked());
    $this->assertSelectedMediaCount('0 items selected');
    // Select the first 2 items.
    $checkboxes[0]->click();
    $this->assertSelectedMediaCount('1 item selected');
    $checkboxes[1]->click();
    $this->assertSelectedMediaCount('2 items selected');
    $this->assertTrue($checkboxes[0]->isChecked());
    $this->assertTrue($checkboxes[1]->isChecked());
    $this->assertFalse($checkboxes[2]->isChecked());
    $this->assertFalse($checkboxes[3]->isChecked());
    // Close the dialog, reopen it and assert not is selected again.
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();
    $this->openMediaLibraryForField('field_unlimited_media');
    $checkboxes = $this->getCheckboxes();
    $this->assertGreaterThanOrEqual(4, count($checkboxes));
    $this->assertFalse($checkboxes[0]->isChecked());
    $this->assertFalse($checkboxes[1]->isChecked());
    $this->assertFalse($checkboxes[2]->isChecked());
    $this->assertFalse($checkboxes[3]->isChecked());
    $assert_session->elementExists('css', '.ui-dialog-titlebar-close')->click();

    // Finally, save the form.
    $assert_session->elementExists('css', '.js-media-library-widget-toggle-weight')->click();
    $this->submitForm([
      'title[0][value]' => 'My page',
      'field_twin_media[selection][0][weight]' => '3',
    ], 'Save');
    $assert_session->pageTextContains('Basic Page My page has been created');
    // We removed this item earlier.
    $assert_session->pageTextNotContains('Cat');
    // This item was never selected.
    $assert_session->pageTextNotContains('Snake');
    // "Turtle" should come after "Dog", since we changed the weight.
    $assert_session->elementExists('css', '.field--name-field-twin-media > .field__items > .field__item:last-child:contains("Turtle")');
    // Make sure everything that was selected shows up.
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextContains('Turtle');

    // Re-edit the content and make a new selection.
    $this->drupalGet('node/1/edit');
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextNotContains('Cat');
    $assert_session->pageTextNotContains('Bear');
    $assert_session->pageTextNotContains('Horse');
    $assert_session->pageTextContains('Turtle');
    $assert_session->pageTextNotContains('Snake');
    $this->openMediaLibraryForField('field_unlimited_media');
    // Select all media items of type one (should also contain Dog, again).
    $this->selectMediaItem(0);
    $this->selectMediaItem(1);
    $this->selectMediaItem(2);
    $this->selectMediaItem(3);
    $this->pressInsertSelected('Added 4 media items.');
    $this->waitForText('Dog');
    $assert_session->pageTextContains('Cat');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextContains('Horse');
    $assert_session->pageTextContains('Turtle');
    $assert_session->pageTextNotContains('Snake');
    $this->submitForm([], 'Save');
    $assert_session->pageTextContains('Dog');
    $assert_session->pageTextContains('Cat');
    $assert_session->pageTextContains('Bear');
    $assert_session->pageTextContains('Horse');
    $assert_session->pageTextContains('Turtle');
    $assert_session->pageTextNotContains('Snake');
  }

}
