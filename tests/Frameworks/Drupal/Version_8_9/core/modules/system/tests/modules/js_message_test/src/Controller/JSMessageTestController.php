<?php

namespace Drupal\js_message_test\Controller;

/**
 * Test Controller to show message links.
 */
class JSMessageTestController {

  /**
   * Gets the test types.
   *
   * @return string[]
   *   The test types.
   */
  public static function getTypes() {
    return ['status', 'error', 'warning'];
  }

  /**
   * Gets the test messages selectors.
   *
   * @return string[]
   *   The test test messages selectors.
   *
   * @see core/modules/system/tests/themes/test_messages/templates/status-messages.html.twig
   */
  public static function getMessagesSelectors() {
    return ['', '[data-drupal-messages-other]'];
  }

  /**
   * Displays links to show messages via Javascript.
   *
   * @return array
   *   Render array for links.
   */
  public function messageLinks() {
    $buttons = [];
    foreach (static::getMessagesSelectors() as $messagesSelector) {
      $buttons[$messagesSelector] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => "Message area: $messagesSelector",
        '#attributes' => [
          'data-drupal-messages-area' => $messagesSelector,
        ],
      ];
      foreach (static::getTypes() as $type) {
        $buttons[$messagesSelector]["add-$type"] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => "Add $type",
          '#attributes' => [
            'type' => 'button',
            'id' => "add-$messagesSelector-$type",
            'data-type' => $type,
            'data-action' => 'add',
          ],
        ];
        $buttons[$messagesSelector]["remove-$type"] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => "Remove $type",
          '#attributes' => [
            'type' => 'button',
            'id' => "remove-$messagesSelector-$type",
            'data-type' => $type,
            'data-action' => 'remove',
          ],
        ];
      }
    }
    // Add alternative message area.
    $buttons[static::getMessagesSelectors()[1]]['messages-other-area'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'data-drupal-messages-other' => TRUE,
      ],
    ];
    $buttons['add-multiple'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Add multiple",
      '#attributes' => [
        'type' => 'button',
        'id' => 'add-multiple',
        'data-action' => 'add-multiple',
      ],
    ];
    $buttons['remove-multiple'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Remove multiple",
      '#attributes' => [
        'type' => 'button',
        'id' => 'remove-multiple',
        'data-action' => 'remove-multiple',
      ],
    ];
    $buttons['add-multiple-error'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Add multiple 'error' and one 'status'",
      '#attributes' => [
        'type' => 'button',
        'id' => 'add-multiple-error',
        'data-action' => 'add-multiple-error',
      ],
    ];
    $buttons['remove-type'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Remove 'error' type",
      '#attributes' => [
        'type' => 'button',
        'id' => 'remove-type',
        'data-action' => 'remove-type',
      ],
    ];
    $buttons['clear-all'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Clear all",
      '#attributes' => [
        'type' => 'button',
        'id' => 'clear-all',
        'data-action' => 'clear-all',
      ],
    ];

    $buttons['id-no-status'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => "Id no status",
      '#attributes' => [
        'type' => 'button',
        'id' => 'id-no-status',
        'data-action' => 'id-no-status',
      ],
    ];

    return $buttons + [
      '#attached' => [
        'library' => [
          'js_message_test/show_message',
        ],
        'drupalSettings' => [
          'testMessages' => [
            'selectors' => static::getMessagesSelectors(),
            'types' => static::getTypes(),
          ],
        ],
      ],
    ];
  }

}
