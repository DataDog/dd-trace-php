<?php

namespace Drupal\Tests\datetime\Functional;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Core\Form\FormState;

/**
 * Tests Datetime field functionality.
 *
 * @group datetime
 */
class DateTimeFieldTest extends DateTestBase {

  /**
   * The default display settings to use for the formatters.
   *
   * @var array
   */
  protected $defaultSettings = ['timezone_override' => ''];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function getTestFieldType() {
    return 'datetime';
  }

  /**
   * Tests date field functionality.
   */
  public function testDateField() {
    $field_name = $this->fieldStorage->getName();

    $display_repository = \Drupal::service('entity_display.repository');

    // Loop through defined timezones to test that date-only fields work at the
    // extremes.
    foreach (static::$timezones as $timezone) {

      $this->setSiteTimezone($timezone);
      $this->assertEquals($timezone, $this->config('system.date')->get('timezone.default'), 'Time zone set to ' . $timezone);

      // Display creation form.
      $this->drupalGet('entity_test/add');
      $this->assertSession()->fieldValueEquals("{$field_name}[0][value][date]", '');
      $this->assertSession()->elementExists('xpath', '//*[@id="edit-' . $field_name . '-wrapper"]//label[contains(@class,"js-form-required")]');
      $this->assertSession()->fieldNotExists("{$field_name}[0][value][time]");
      // ARIA described-by.
      $this->assertSession()->elementExists('xpath', '//input[@aria-describedby="edit-' . $field_name . '-0-value--description"]');
      $this->assertSession()->elementExists('xpath', '//div[@id="edit-' . $field_name . '-0-value--description"]');

      // Build up a date in the UTC timezone. Note that using this will also
      // mimic the user in a different timezone simply entering '2012-12-31' via
      // the UI.
      $value = '2012-12-31 00:00:00';
      $date = new DrupalDateTime($value, DateTimeItemInterface::STORAGE_TIMEZONE);

      // Submit a valid date and ensure it is accepted.
      $date_format = DateFormat::load('html_date')->getPattern();
      $time_format = DateFormat::load('html_time')->getPattern();

      $edit = [
        "{$field_name}[0][value][date]" => $date->format($date_format),
      ];
      $this->submitForm($edit, 'Save');
      preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
      $id = $match[1];
      $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');
      $this->assertSession()->responseContains($date->format($date_format));
      $this->assertSession()->responseNotContains($date->format($time_format));

      // Verify the date doesn't change if using a timezone that is UTC+12 when
      // the entity is edited through the form.
      $entity = EntityTest::load($id);
      $this->assertEquals('2012-12-31', $entity->{$field_name}->value);
      $this->drupalGet('entity_test/manage/' . $id . '/edit');
      $this->submitForm([], 'Save');
      $this->drupalGet('entity_test/manage/' . $id . '/edit');
      $this->submitForm([], 'Save');
      $this->drupalGet('entity_test/manage/' . $id . '/edit');
      $this->submitForm([], 'Save');
      $entity = EntityTest::load($id);
      $this->assertEquals('2012-12-31', $entity->{$field_name}->value);

      // Reset display options since these get changed below.
      $this->displayOptions = [
        'type' => 'datetime_default',
        'label' => 'hidden',
        'settings' => ['format_type' => 'medium'] + $this->defaultSettings,
      ];
      // Verify that the date is output according to the formatter settings.
      $options = [
        'format_type' => ['short', 'medium', 'long'],
      ];
      // Formats that display a time component for date-only fields will display
      // the default time, so that is applied before calculating the expected
      // value.
      $this->massageTestDate($date);
      foreach ($options as $setting => $values) {
        foreach ($values as $new_value) {
          // Update the entity display settings.
          $this->displayOptions['settings'] = [$setting => $new_value] + $this->defaultSettings;
          $this->container->get('entity_display.repository')
            ->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
            ->setComponent($field_name, $this->displayOptions)
            ->save();

          $this->renderTestEntity($id);
          switch ($setting) {
            case 'format_type':
              // Verify that a date is displayed. Since this is a date-only
              // field, it is expected to display the time as 00:00:00.
              /** @var \Drupal\Core\Datetime\DateFormatterInterface $date_formatter */
              $date_formatter = $this->container->get('date.formatter');
              $expected = $date_formatter->format($date->getTimestamp(), $new_value, '', DateTimeItemInterface::STORAGE_TIMEZONE);
              $expected_iso = $date_formatter->format($date->getTimestamp(), 'custom', 'Y-m-d\TH:i:s\Z', DateTimeItemInterface::STORAGE_TIMEZONE);
              $output = $this->renderTestEntity($id);
              $expected_markup = '<time datetime="' . $expected_iso . '">' . $expected . '</time>';
              $this->assertStringContainsString($expected_markup, $output, new FormattableMarkup('Formatted date field using %value format displayed as %expected with %expected_iso attribute in %timezone.', [
                '%value' => $new_value,
                '%expected' => $expected,
                '%expected_iso' => $expected_iso,
                '%timezone' => $timezone,
              ]));
              break;
          }
        }
      }

      // Verify that the plain formatter works.
      $this->displayOptions['type'] = 'datetime_plain';
      $this->displayOptions['settings'] = $this->defaultSettings;
      $display_repository
        ->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
        ->setComponent($field_name, $this->displayOptions)
        ->save();
      $expected = $date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);
      $output = $this->renderTestEntity($id);
      $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using plain format displayed as %expected in %timezone.', [
        '%expected' => $expected,
        '%timezone' => $timezone,
      ]));

      // Verify that the 'datetime_custom' formatter works.
      $this->displayOptions['type'] = 'datetime_custom';
      $this->displayOptions['settings'] = ['date_format' => 'm/d/Y'] + $this->defaultSettings;
      $display_repository
        ->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
        ->setComponent($field_name, $this->displayOptions)
        ->save();
      $expected = $date->format($this->displayOptions['settings']['date_format']);
      $output = $this->renderTestEntity($id);
      $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using datetime_custom format displayed as %expected in %timezone.', [
        '%expected' => $expected,
        '%timezone' => $timezone,
      ]));

      // Test that allowed markup in custom format is preserved and XSS is
      // removed.
      $this->displayOptions['settings']['date_format'] = '\\<\\s\\t\\r\\o\\n\\g\\>m/d/Y\\<\\/\\s\\t\\r\\o\\n\\g\\>\\<\\s\\c\\r\\i\\p\\t\\>\\a\\l\\e\\r\\t\\(\\S\\t\\r\\i\\n\\g\\.\\f\\r\\o\\m\\C\\h\\a\\r\\C\\o\\d\\e\\(\\8\\8\\,\\8\\3\\,\\8\\3\\)\\)\\<\\/\\s\\c\\r\\i\\p\\t\\>';
      $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
        ->setComponent($field_name, $this->displayOptions)
        ->save();
      $expected = '<strong>' . $date->format('m/d/Y') . '</strong>alert(String.fromCharCode(88,83,83))';
      $output = $this->renderTestEntity($id);
      $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using daterange_custom format displayed as %expected in %timezone.', [
        '%expected' => $expected,
        '%timezone' => $timezone,
      ]));

      // Verify that the 'datetime_time_ago' formatter works for intervals in the
      // past.  First update the test entity so that the date difference always
      // has the same interval.  Since the database always stores UTC, and the
      // interval will use this, force the test date to use UTC and not the local
      // or user timezone.
      $timestamp = REQUEST_TIME - 87654321;
      $entity = EntityTest::load($id);
      $field_name = $this->fieldStorage->getName();
      $date = DrupalDateTime::createFromTimestamp($timestamp, 'UTC');
      $entity->{$field_name}->value = $date->format($date_format);
      $entity->save();

      $this->displayOptions['type'] = 'datetime_time_ago';
      $this->displayOptions['settings'] = [
        'future_format' => '@interval in the future',
        'past_format' => '@interval in the past',
        'granularity' => 3,
      ];
      $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
        ->setComponent($field_name, $this->displayOptions)
        ->save();
      $expected = new FormattableMarkup($this->displayOptions['settings']['past_format'], [
        '@interval' => $this->dateFormatter->formatTimeDiffSince($timestamp, ['granularity' => $this->displayOptions['settings']['granularity']]),
      ]);
      $output = $this->renderTestEntity($id);
      $this->assertStringContainsString((string) $expected, $output, new FormattableMarkup('Formatted date field using datetime_time_ago format displayed as %expected in %timezone.', [
        '%expected' => $expected,
        '%timezone' => $timezone,
      ]));

      // Verify that the 'datetime_time_ago' formatter works for intervals in the
      // future.  First update the test entity so that the date difference always
      // has the same interval.  Since the database always stores UTC, and the
      // interval will use this, force the test date to use UTC and not the local
      // or user timezone.
      $timestamp = REQUEST_TIME + 87654321;
      $entity = EntityTest::load($id);
      $field_name = $this->fieldStorage->getName();
      $date = DrupalDateTime::createFromTimestamp($timestamp, 'UTC');
      $entity->{$field_name}->value = $date->format($date_format);
      $entity->save();

      $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
        ->setComponent($field_name, $this->displayOptions)
        ->save();
      $expected = new FormattableMarkup($this->displayOptions['settings']['future_format'], [
        '@interval' => $this->dateFormatter->formatTimeDiffUntil($timestamp, ['granularity' => $this->displayOptions['settings']['granularity']]),
      ]);
      $output = $this->renderTestEntity($id);
      $this->assertStringContainsString((string) $expected, $output, new FormattableMarkup('Formatted date field using datetime_time_ago format displayed as %expected in %timezone.', [
        '%expected' => $expected,
        '%timezone' => $timezone,
      ]));
    }
  }

  /**
   * Tests date and time field.
   */
  public function testDatetimeField() {
    $field_name = $this->fieldStorage->getName();
    $field_label = $this->field->label();
    // Change the field to a datetime field.
    $this->fieldStorage->setSetting('datetime_type', 'datetime');
    $this->fieldStorage->save();

    // Display creation form.
    $this->drupalGet('entity_test/add');
    $this->assertSession()->fieldValueEquals("{$field_name}[0][value][date]", '');
    $this->assertSession()->fieldValueEquals("{$field_name}[0][value][time]", '');
    $this->assertSession()->elementTextContains('xpath', '//fieldset[@id="edit-' . $field_name . '-0"]/legend', $field_label);
    $this->assertSession()->elementExists('xpath', '//fieldset[@aria-describedby="edit-' . $field_name . '-0--description"]');
    $this->assertSession()->elementExists('xpath', '//div[@id="edit-' . $field_name . '-0--description"]');

    // Build up a date in the UTC timezone.
    $value = '2012-12-31 00:00:00';
    $date = new DrupalDateTime($value, 'UTC');

    // Update the timezone to the system default.
    $date->setTimezone(timezone_open(date_default_timezone_get()));

    // Submit a valid date and ensure it is accepted.
    $date_format = DateFormat::load('html_date')->getPattern();
    $time_format = DateFormat::load('html_time')->getPattern();

    $edit = [
      "{$field_name}[0][value][date]" => $date->format($date_format),
      "{$field_name}[0][value][time]" => $date->format($time_format),
    ];
    $this->submitForm($edit, 'Save');
    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $id = $match[1];
    $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');
    $this->assertSession()->responseContains($date->format($date_format));
    $this->assertSession()->responseContains($date->format($time_format));

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');

    // Verify that the date is output according to the formatter settings.
    $options = [
      'format_type' => ['short', 'medium', 'long'],
    ];
    foreach ($options as $setting => $values) {
      foreach ($values as $new_value) {
        // Update the entity display settings.
        $this->displayOptions['settings'] = [$setting => $new_value] + $this->defaultSettings;
        $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
          ->setComponent($field_name, $this->displayOptions)
          ->save();

        $this->renderTestEntity($id);
        switch ($setting) {
          case 'format_type':
            // Verify that a date is displayed.
            $date_formatter = $this->container->get('date.formatter');
            $expected = $date_formatter->format($date->getTimestamp(), $new_value);
            $expected_iso = $date_formatter->format($date->getTimestamp(), 'custom', 'Y-m-d\TH:i:s\Z', 'UTC');
            $output = $this->renderTestEntity($id);
            $expected_markup = '<time datetime="' . $expected_iso . '">' . $expected . '</time>';
            $this->assertStringContainsString($expected_markup, $output, new FormattableMarkup('Formatted date field using %value format displayed as %expected with %expected_iso attribute.', ['%value' => $new_value, '%expected' => $expected, '%expected_iso' => $expected_iso]));
            break;
        }
      }
    }

    // Verify that the plain formatter works.
    $this->displayOptions['type'] = 'datetime_plain';
    $this->displayOptions['settings'] = $this->defaultSettings;
    $display_repository
      ->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
      ->setComponent($field_name, $this->displayOptions)
      ->save();
    $expected = $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $output = $this->renderTestEntity($id);
    $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using plain format displayed as %expected.', ['%expected' => $expected]));

    // Verify that the 'datetime_custom' formatter works.
    $this->displayOptions['type'] = 'datetime_custom';
    $this->displayOptions['settings'] = ['date_format' => 'm/d/Y g:i:s A'] + $this->defaultSettings;
    $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
      ->setComponent($field_name, $this->displayOptions)
      ->save();
    $expected = $date->format($this->displayOptions['settings']['date_format']);
    $output = $this->renderTestEntity($id);
    $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using datetime_custom format displayed as %expected.', ['%expected' => $expected]));

    // Verify that the 'timezone_override' setting works.
    $this->displayOptions['type'] = 'datetime_custom';
    $this->displayOptions['settings'] = ['date_format' => 'm/d/Y g:i:s A', 'timezone_override' => 'America/New_York'] + $this->defaultSettings;
    $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
      ->setComponent($field_name, $this->displayOptions)
      ->save();
    $expected = $date->format($this->displayOptions['settings']['date_format'], ['timezone' => 'America/New_York']);
    $output = $this->renderTestEntity($id);
    $this->assertStringContainsString($expected, $output, new FormattableMarkup('Formatted date field using datetime_custom format displayed as %expected.', ['%expected' => $expected]));

    // Verify that the 'datetime_time_ago' formatter works for intervals in the
    // past.  First update the test entity so that the date difference always
    // has the same interval.  Since the database always stores UTC, and the
    // interval will use this, force the test date to use UTC and not the local
    // or user timezone.
    $timestamp = REQUEST_TIME - 87654321;
    $entity = EntityTest::load($id);
    $field_name = $this->fieldStorage->getName();
    $date = DrupalDateTime::createFromTimestamp($timestamp, 'UTC');
    $entity->{$field_name}->value = $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $entity->save();

    $this->displayOptions['type'] = 'datetime_time_ago';
    $this->displayOptions['settings'] = [
      'future_format' => '@interval from now',
      'past_format' => '@interval earlier',
      'granularity' => 3,
    ];
    $display_repository->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
      ->setComponent($field_name, $this->displayOptions)
      ->save();
    $expected = new FormattableMarkup($this->displayOptions['settings']['past_format'], [
      '@interval' => $this->dateFormatter->formatTimeDiffSince($timestamp, ['granularity' => $this->displayOptions['settings']['granularity']]),
    ]);
    $output = $this->renderTestEntity($id);
    $this->assertStringContainsString((string) $expected, $output, new FormattableMarkup('Formatted date field using datetime_time_ago format displayed as %expected.', ['%expected' => $expected]));

    // Verify that the 'datetime_time_ago' formatter works for intervals in the
    // future.  First update the test entity so that the date difference always
    // has the same interval.  Since the database always stores UTC, and the
    // interval will use this, force the test date to use UTC and not the local
    // or user timezone.
    $timestamp = REQUEST_TIME + 87654321;
    $entity = EntityTest::load($id);
    $field_name = $this->fieldStorage->getName();
    $date = DrupalDateTime::createFromTimestamp($timestamp, 'UTC');
    $entity->{$field_name}->value = $date->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
    $entity->save();

    $display_repository
      ->getViewDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle(), 'full')
      ->setComponent($field_name, $this->displayOptions)
      ->save();
    $expected = new FormattableMarkup($this->displayOptions['settings']['future_format'], [
      '@interval' => $this->dateFormatter->formatTimeDiffUntil($timestamp, ['granularity' => $this->displayOptions['settings']['granularity']]),
    ]);
    $output = $this->renderTestEntity($id);
    $this->assertStringContainsString((string) $expected, $output, new FormattableMarkup('Formatted date field using datetime_time_ago format displayed as %expected.', ['%expected' => $expected]));

    // Test the required field validation error message.
    $entity = EntityTest::create(['name' => 'test datetime required message']);
    $form = \Drupal::entityTypeManager()->getFormObject('entity_test', 'default')->setEntity($entity);
    $form_state = new FormState();
    \Drupal::formBuilder()->submitForm($form, $form_state);
    $errors = $form_state->getErrors();
    $arguments = $errors["{$field_name}][0][value"]->getArguments();
    $expected_error_message = new FormattableMarkup('The %field date is required. Please enter a date in the format %format.', ['%field' => $field_label, '%format' => $arguments['%format']]);
    $actual_error_message = $errors["{$field_name}][0][value"]->__toString();
    $this->assertEquals($expected_error_message->__toString(), $actual_error_message);
    // Verify the format value is in the "YYYY-MM-DD HH:MM:SS" format.
    $this->assertMatchesRegularExpression('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $arguments['%format']);
  }

  /**
   * Tests Date List Widget functionality.
   */
  public function testDatelistWidget() {
    $field_name = $this->fieldStorage->getName();
    $field_label = $this->field->label();

    // Ensure field is set to a date only field.
    $this->fieldStorage->setSetting('datetime_type', 'date');
    $this->fieldStorage->save();

    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');

    // Change the widget to a datelist widget.
    $display_repository->getFormDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle())
      ->setComponent($field_name, [
        'type' => 'datetime_datelist',
        'settings' => [
          'date_order' => 'YMD',
        ],
      ])
      ->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Display creation form.
    $this->drupalGet('entity_test/add');
    $this->assertSession()->elementTextContains('xpath', '//fieldset[@id="edit-' . $field_name . '-0"]/legend', $field_label);
    $this->assertSession()->elementExists('xpath', '//fieldset[@aria-describedby="edit-' . $field_name . '-0--description"]');
    $this->assertSession()->elementExists('xpath', '//div[@id="edit-' . $field_name . '-0--description"]');

    // Assert that Hour and Minute Elements do not appear on Date Only
    $this->assertSession()->elementNotExists('xpath', "//*[@id=\"edit-$field_name-0-value-hour\"]");
    $this->assertSession()->elementNotExists('xpath', "//*[@id=\"edit-$field_name-0-value-minute\"]");

    // Go to the form display page to assert that increment option does not appear on Date Only
    $fieldEditUrl = 'entity_test/structure/entity_test/form-display';
    $this->drupalGet($fieldEditUrl);

    // Click on the widget settings button to open the widget settings form.
    $this->submitForm([], $field_name . "_settings_edit");
    $xpathIncr = "//select[starts-with(@id, \"edit-fields-$field_name-settings-edit-form-settings-increment\")]";
    $this->assertSession()->elementNotExists('xpath', $xpathIncr);

    // Change the field to a datetime field.
    $this->fieldStorage->setSetting('datetime_type', 'datetime');
    $this->fieldStorage->save();

    // Change the widget to a datelist widget.
    $display_repository->getFormDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle())
      ->setComponent($field_name, [
        'type' => 'datetime_datelist',
        'settings' => [
          'increment' => 1,
          'date_order' => 'YMD',
          'time_type' => '12',
        ],
      ])
      ->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Go to the form display page to assert that increment option does appear on Date Time
    $fieldEditUrl = 'entity_test/structure/entity_test/form-display';
    $this->drupalGet($fieldEditUrl);

    // Click on the widget settings button to open the widget settings form.
    $this->submitForm([], $field_name . "_settings_edit");
    $this->assertSession()->elementExists('xpath', $xpathIncr);

    // Display creation form.
    $this->drupalGet('entity_test/add');

    // Year element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-year\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-year", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-year", 'Year');
    // Month element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-month\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-month", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-month", 'Month');
    // Day element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-day\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-day", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-day", 'Day');
    // Hour element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-hour\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-hour", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-hour", 'Hour');
    // Minute element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-minute\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-minute", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-minute", 'Minute');
    // No Second element.
    $this->assertSession()->elementNotExists('xpath', "//*[@id=\"edit-$field_name-0-value-second\"]");
    // AMPM element.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-ampm\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-ampm", '')->isSelected());
    $this->assertSession()->optionExists("edit-$field_name-0-value-ampm", 'AM/PM');

    // Submit a valid date and ensure it is accepted.
    $date_value = ['year' => 2012, 'month' => 12, 'day' => 31, 'hour' => 5, 'minute' => 15];

    $edit = [];
    // Add the ampm indicator since we are testing 12 hour time.
    $date_value['ampm'] = 'am';
    foreach ($date_value as $part => $value) {
      $edit["{$field_name}[0][value][$part]"] = $value;
    }

    $this->submitForm($edit, 'Save');
    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $id = $match[1];
    $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');

    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-year", '2012')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-month", '12')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-day", '31')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-hour", '5')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-minute", '15')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-ampm", 'am')->isSelected());

    // Test the widget using increment other than 1 and 24 hour mode.
    $display_repository->getFormDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle())
      ->setComponent($field_name, [
        'type' => 'datetime_datelist',
        'settings' => [
          'increment' => 15,
          'date_order' => 'YMD',
          'time_type' => '24',
        ],
      ])
      ->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Display creation form.
    $this->drupalGet('entity_test/add');

    // Other elements are unaffected by the changed settings.
    $this->assertSession()->elementExists('xpath', "//*[@id=\"edit-$field_name-0-value-hour\"]");
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-hour", '')->isSelected());
    $this->assertSession()->elementNotExists('xpath', "//*[@id=\"edit-$field_name-0-value-ampm\"]");
    // Submit a valid date and ensure it is accepted.
    $date_value = ['year' => 2012, 'month' => 12, 'day' => 31, 'hour' => 17, 'minute' => 15];

    $edit = [];
    foreach ($date_value as $part => $value) {
      $edit["{$field_name}[0][value][$part]"] = $value;
    }

    $this->submitForm($edit, 'Save');
    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $id = $match[1];
    $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');

    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-year", '2012')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-month", '12')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-day", '31')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-hour", '17')->isSelected());
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-minute", '15')->isSelected());

    // Test the widget for partial completion of fields.
    $display_repository->getFormDisplay($this->field->getTargetEntityTypeId(), $this->field->getTargetBundle())
      ->setComponent($field_name, [
        'type' => 'datetime_datelist',
        'settings' => [
          'increment' => 1,
          'date_order' => 'YMD',
          'time_type' => '24',
        ],
      ])
      ->save();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    // Test the widget for validation notifications.
    foreach ($this->datelistDataProvider($field_label) as $data) {
      [$date_value, $expected] = $data;

      // Display creation form.
      $this->drupalGet('entity_test/add');

      // Submit a partial date and ensure and error message is provided.
      $edit = [];
      foreach ($date_value as $part => $value) {
        $edit["{$field_name}[0][value][$part]"] = $value;
      }

      $this->submitForm($edit, 'Save');
      $this->assertSession()->statusCodeEquals(200);
      foreach ($expected as $expected_text) {
        $this->assertSession()->pageTextContains($expected_text);
      }
    }

    // Test the widget for complete input with zeros as part of selections.
    $this->drupalGet('entity_test/add');

    $date_value = ['year' => 2012, 'month' => '12', 'day' => '31', 'hour' => '0', 'minute' => '0'];
    $edit = [];
    foreach ($date_value as $part => $value) {
      $edit["{$field_name}[0][value][$part]"] = $value;
    }

    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
    preg_match('|entity_test/manage/(\d+)|', $this->getUrl(), $match);
    $id = $match[1];
    $this->assertSession()->pageTextContains('entity_test ' . $id . ' has been created.');

    // Test the widget to ensure zeros are not deselected on validation.
    $this->drupalGet('entity_test/add');

    $date_value = ['year' => 2012, 'month' => '12', 'day' => '31', 'hour' => '', 'minute' => '0'];
    $edit = [];
    foreach ($date_value as $part => $value) {
      $edit["{$field_name}[0][value][$part]"] = $value;
    }

    $this->submitForm($edit, 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertTrue($this->assertSession()->optionExists("edit-$field_name-0-value-minute", '0')->isSelected());
  }

  /**
   * The data provider for testing the validation of the datelist widget.
   *
   * @param string $field_label
   *   The label of the field being tested.
   *
   * @return array
   *   An array of datelist input permutations to test.
   */
  protected function datelistDataProvider($field_label) {
    return [
      // Nothing selected.
      [
        ['year' => '', 'month' => '', 'day' => '', 'hour' => '', 'minute' => ''],
        ["The $field_label date is required."],
      ],
      // Year only selected, validation error on Month, Day, Hour, Minute.
      [
        ['year' => 2012, 'month' => '', 'day' => '', 'hour' => '', 'minute' => ''],
        [
          "The $field_label date is incomplete.",
          'A value must be selected for month.',
          'A value must be selected for day.',
          'A value must be selected for hour.',
          'A value must be selected for minute.',
        ],
      ],
      // Year and Month selected, validation error on Day, Hour, Minute.
      [
        ['year' => 2012, 'month' => '12', 'day' => '', 'hour' => '', 'minute' => ''],
        [
          "The $field_label date is incomplete.",
          'A value must be selected for day.',
          'A value must be selected for hour.',
          'A value must be selected for minute.',
        ],
      ],
      // Year, Month and Day selected, validation error on Hour, Minute.
      [
        ['year' => 2012, 'month' => '12', 'day' => '31', 'hour' => '', 'minute' => ''],
        [
          "The $field_label date is incomplete.",
          'A value must be selected for hour.',
          'A value must be selected for minute.',
        ],
      ],
      // Year, Month, Day and Hour selected, validation error on Minute only.
      [
        ['year' => 2012, 'month' => '12', 'day' => '31', 'hour' => '0', 'minute' => ''],
        [
          "The $field_label date is incomplete.",
          'A value must be selected for minute.',
        ],
      ],
    ];
  }

  /**
   * Tests default value functionality.
   */
  public function testDefaultValue() {
    // Create a test content type.
    $this->drupalCreateContentType(['type' => 'date_content']);

    // Create a field storage with settings to validate.
    $field_name = mb_strtolower($this->randomMachineName());
    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => ['datetime_type' => 'date'],
    ]);
    $field_storage->save();

    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => 'date_content',
    ]);
    $field->save();

    // Loop through defined timezones to test that date-only defaults work at
    // the extremes.
    foreach (static::$timezones as $timezone) {

      $this->setSiteTimezone($timezone);
      $this->assertEquals($timezone, $this->config('system.date')->get('timezone.default'), 'Time zone set to ' . $timezone);

      // Set now as default_value.
      $field_edit = [
        'set_default_value' => '1',
        'default_value_input[default_date_type]' => 'now',
      ];
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->submitForm($field_edit, 'Save settings');

      // Check that default value is selected in default value form.
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->assertTrue($this->assertSession()->optionExists('edit-default-value-input-default-date-type', 'now')->isSelected());
      // Check that the relative default value is empty.
      $this->assertSession()->fieldValueEquals('default_value_input[default_date]', '');

      // Check if default_date has been stored successfully.
      $config_entity = $this->config('field.field.node.date_content.' . $field_name)
        ->get();
      $this->assertEquals(['default_date_type' => 'now', 'default_date' => 'now'], $config_entity['default_value'][0], 'Default value has been stored successfully');

      // Clear field cache in order to avoid stale cache values.
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

      // Create a new node to check that datetime field default value is today.
      $new_node = Node::create(['type' => 'date_content']);
      $expected_date = new DrupalDateTime('now', date_default_timezone_get());
      $this->assertEquals($expected_date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT), $new_node->get($field_name)->offsetGet(0)->value);

      // Set an invalid relative default_value to test validation.
      $field_edit = [
        'set_default_value' => '1',
        'default_value_input[default_date_type]' => 'relative',
        'default_value_input[default_date]' => 'invalid date',
      ];
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->submitForm($field_edit, 'Save settings');

      $this->assertSession()->pageTextContains('The relative date value entered is invalid.');

      // Set a relative default_value.
      $field_edit = [
        'set_default_value' => '1',
        'default_value_input[default_date_type]' => 'relative',
        'default_value_input[default_date]' => '+90 days',
      ];
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->submitForm($field_edit, 'Save settings');

      // Check that default value is selected in default value form.
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->assertTrue($this->assertSession()->optionExists('edit-default-value-input-default-date-type', 'relative')->isSelected());
      // Check that the relative default value is displayed.
      $this->assertSession()->fieldValueEquals('default_value_input[default_date]', '+90 days');

      // Check if default_date has been stored successfully.
      $config_entity = $this->config('field.field.node.date_content.' . $field_name)
        ->get();
      $this->assertEquals(['default_date_type' => 'relative', 'default_date' => '+90 days'], $config_entity['default_value'][0], 'Default value has been stored successfully');

      // Clear field cache in order to avoid stale cache values.
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

      // Create a new node to check that datetime field default value is +90
      // days.
      $new_node = Node::create(['type' => 'date_content']);
      $expected_date = new DrupalDateTime('+90 days', date_default_timezone_get());
      $this->assertEquals($expected_date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT), $new_node->get($field_name)->offsetGet(0)->value);

      // Remove default value.
      $field_edit = [
        'set_default_value' => '1',
        'default_value_input[default_date_type]' => '',
      ];
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->submitForm($field_edit, 'Save settings');

      // Check that default value is selected in default value form.
      $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name);
      $this->assertTrue($this->assertSession()->optionExists('edit-default-value-input-default-date-type', '')->isSelected());
      // Check that the relative default value is empty.
      $this->assertSession()->fieldValueEquals('default_value_input[default_date]', '');

      // Check if default_date has been stored successfully.
      $config_entity = $this->config('field.field.node.date_content.' . $field_name)
        ->get();
      $this->assertEmpty($config_entity['default_value'], 'Empty default value has been stored successfully');

      // Clear field cache in order to avoid stale cache values.
      \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

      // Create a new node to check that datetime field default value is not
      // set.
      $new_node = Node::create(['type' => 'date_content']);
      $this->assertNull($new_node->get($field_name)->value, 'Default value is not set');
    }
  }

  /**
   * Tests that invalid values are caught and marked as invalid.
   */
  public function testInvalidField() {
    // Change the field to a datetime field.
    $this->fieldStorage->setSetting('datetime_type', 'datetime');
    $this->fieldStorage->save();
    $field_name = $this->fieldStorage->getName();

    // Display creation form.
    $this->drupalGet('entity_test/add');
    $this->assertSession()->fieldValueEquals("{$field_name}[0][value][date]", '');
    $this->assertSession()->fieldValueEquals("{$field_name}[0][value][time]", '');

    // Submit invalid dates and ensure they is not accepted.
    $date_value = '';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => '12:00:00',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid year value.
    $date_value = 'aaaa-12-01';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => '00:00:00',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid month value.
    $date_value = '2012-75-01';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => '00:00:00',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid day value.
    $date_value = '2012-12-99';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => '00:00:00',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid time value.
    $date_value = '2012-12-01';
    $time_value = '';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => $time_value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid hour value.
    $date_value = '2012-12-01';
    $time_value = '49:00:00';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => $time_value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid minutes value.
    $date_value = '2012-12-01';
    $time_value = '12:99:00';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => $time_value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');

    // Invalid seconds value.
    $date_value = '2012-12-01';
    $time_value = '12:15:99';
    $edit = [
      "{$field_name}[0][value][date]" => $date_value,
      "{$field_name}[0][value][time]" => $time_value,
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('date is invalid');
  }

  /**
   * Tests that 'Date' field storage setting form is disabled if field has data.
   */
  public function testDateStorageSettings() {
    // Create a test content type.
    $this->drupalCreateContentType(['type' => 'date_content']);

    // Create a field storage with settings to validate.
    $field_name = mb_strtolower($this->randomMachineName());
    $field_storage = FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'datetime',
      'settings' => [
        'datetime_type' => 'date',
      ],
    ]);
    $field_storage->save();
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'field_name' => $field_name,
      'bundle' => 'date_content',
    ]);
    $field->save();

    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'date_content')
      ->setComponent($field_name, [
        'type' => 'datetime_default',
      ])
      ->save();
    $edit = [
      'title[0][value]' => $this->randomString(),
      'body[0][value]' => $this->randomString(),
      $field_name . '[0][value][date]' => '2016-04-01',
    ];
    $this->drupalGet('node/add/date_content');
    $this->submitForm($edit, 'Save');
    $this->drupalGet('admin/structure/types/manage/date_content/fields/node.date_content.' . $field_name . '/storage');
    $this->assertSession()->elementsCount('xpath', "//*[@id='edit-settings-datetime-type' and contains(@disabled, 'disabled')]", 1);
  }

}
