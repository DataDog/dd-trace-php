<?php

namespace Drupal\Tests\system\Functional\Ajax;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AddCssCommand;
use Drupal\Core\Ajax\AlertCommand;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Tests\BrowserTestBase;

/**
 * Performs tests on AJAX framework functions.
 *
 * @group Ajax
 */
class FrameworkTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'ajax_test', 'ajax_forms_test'];

  /**
   * Verifies the Ajax rendering of a command in the settings.
   */
  public function testAJAXRender() {
    // Verify that settings command is generated if JavaScript settings exist.
    $commands = $this->drupalGetAjax('ajax-test/render');
    $expected = new SettingsCommand(['ajax' => 'test'], TRUE);
    $this->assertCommand($commands, $expected->render(), 'JavaScript settings command is present.');
  }

  /**
   * Tests AjaxResponse::prepare() AJAX commands ordering.
   */
  public function testOrder() {
    $expected_commands = [];

    // Expected commands, in a very specific order.
    $asset_resolver = \Drupal::service('asset.resolver');
    $css_collection_renderer = \Drupal::service('asset.css.collection_renderer');
    $js_collection_renderer = \Drupal::service('asset.js.collection_renderer');
    $renderer = \Drupal::service('renderer');
    $build['#attached']['library'][] = 'ajax_test/order-css-command';
    $assets = AttachedAssets::createFromRenderArray($build);
    $css_render_array = $css_collection_renderer->render($asset_resolver->getCssAssets($assets, FALSE));
    $expected_commands[1] = new AddCssCommand($renderer->renderRoot($css_render_array));
    $build['#attached']['library'][] = 'ajax_test/order-header-js-command';
    $build['#attached']['library'][] = 'ajax_test/order-footer-js-command';
    $assets = AttachedAssets::createFromRenderArray($build);
    list($js_assets_header, $js_assets_footer) = $asset_resolver->getJsAssets($assets, FALSE);
    $js_header_render_array = $js_collection_renderer->render($js_assets_header);
    $js_footer_render_array = $js_collection_renderer->render($js_assets_footer);
    $expected_commands[2] = new PrependCommand('head', $js_header_render_array);
    $expected_commands[3] = new AppendCommand('body', $js_footer_render_array);
    $expected_commands[4] = new HtmlCommand('body', 'Hello, world!');

    // Load any page with at least one CSS file, at least one JavaScript file
    // and at least one #ajax-powered element. The latter is an assumption of
    // drupalPostAjaxForm(), the two former are assumptions of the Ajax
    // renderer.
    // @todo refactor AJAX Framework + tests to make less assumptions.
    $this->drupalGet('ajax_forms_test_lazy_load_form');

    // Verify AJAX command order — this should always be the order:
    // 1. CSS files
    // 2. JavaScript files in the header
    // 3. JavaScript files in the footer
    // 4. Any other AJAX commands, in whatever order they were added.
    $commands = $this->drupalGetAjax('ajax-test/order');
    $this->assertCommand(array_slice($commands, 0, 1), $expected_commands[1]->render());
    $this->assertCommand(array_slice($commands, 1, 1), $expected_commands[2]->render());
    $this->assertCommand(array_slice($commands, 2, 1), $expected_commands[3]->render());
    $this->assertCommand(array_slice($commands, 3, 1), $expected_commands[4]->render());
  }

  /**
   * Tests the behavior of an error alert command.
   */
  public function testAJAXRenderError() {
    // Verify custom error message.
    $edit = [
      'message' => 'Custom error message.',
    ];
    $commands = $this->drupalGetAjax('ajax-test/render-error', ['query' => $edit]);
    $expected = new AlertCommand($edit['message']);
    $this->assertCommand($commands, $expected->render(), 'Custom error message is output.');
  }

  /**
   * Asserts the array of Ajax commands contains the searched command.
   *
   * An AjaxResponse object stores an array of Ajax commands. This array
   * sometimes includes commands automatically provided by the framework in
   * addition to commands returned by a particular controller. During testing,
   * we're usually interested that a particular command is present, and don't
   * care whether other commands precede or follow the one we're interested in.
   * Additionally, the command we're interested in may include additional data
   * that we're not interested in. Therefore, this function simply asserts that
   * one of the commands in $haystack contains all of the keys and values in
   * $needle. Furthermore, if $needle contains a 'settings' key with an array
   * value, we simply assert that all keys and values within that array are
   * present in the command we're checking, and do not consider it a failure if
   * the actual command contains additional settings that aren't part of
   * $needle.
   *
   * @param $haystack
   *   An array of rendered Ajax commands returned by the server.
   * @param $needle
   *   Array of info we're expecting in one of those commands.
   */
  protected function assertCommand($haystack, $needle) {
    $found = FALSE;
    foreach ($haystack as $command) {
      // If the command has additional settings that we're not testing for, do
      // not consider that a failure.
      if (isset($command['settings']) && is_array($command['settings']) && isset($needle['settings']) && is_array($needle['settings'])) {
        $command['settings'] = array_intersect_key($command['settings'], $needle['settings']);
      }
      // If the command has additional data that we're not testing for, do not
      // consider that a failure. Also, == instead of ===, because we don't
      // require the key/value pairs to be in any particular order
      // (http://php.net/manual/language.operators.array.php).
      if (array_intersect_key($command, $needle) == $needle) {
        $found = TRUE;
        break;
      }
    }
    $this->assertTrue($found);
  }

  /**
   * Requests a path or URL in drupal_ajax format and JSON-decodes the response.
   *
   * @param \Drupal\Core\Url|string $path
   *   Drupal path or URL to request from.
   * @param array $options
   *   Array of URL options.
   * @param array $headers
   *   Array of headers.
   *
   * @return array
   *   Decoded JSON.
   */
  protected function drupalGetAjax($path, array $options = [], array $headers = []) {
    $headers[] = 'X-Requested-With: XMLHttpRequest';
    if (!isset($options['query'][MainContentViewSubscriber::WRAPPER_FORMAT])) {
      $options['query'][MainContentViewSubscriber::WRAPPER_FORMAT] = 'drupal_ajax';
    }
    return Json::decode($this->drupalGet($path, $options, $headers));
  }

}
