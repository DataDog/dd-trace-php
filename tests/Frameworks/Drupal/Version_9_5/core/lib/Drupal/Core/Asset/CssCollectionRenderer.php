<?php

namespace Drupal\Core\Asset;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\State\StateInterface;

/**
 * Renders CSS assets.
 */
class CssCollectionRenderer implements AssetCollectionRendererInterface {

  /**
   * The state key/value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs a CssCollectionRenderer.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key/value store.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(StateInterface $state, FileUrlGeneratorInterface $file_url_generator = NULL) {
    $this->state = $state;
    if (!$file_url_generator) {
      @trigger_error('Calling CssCollectionRenderer::__construct() without the $file_url_generator argument is deprecated in drupal:9.3.0 and will be required before drupal:10.0.0. See https://www.drupal.org/node/2549139.', E_USER_DEPRECATED);
      $file_url_generator = \Drupal::service('file_url_generator');
    }
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public function render(array $css_assets) {
    $elements = [];

    // A dummy query-string is added to filenames, to gain control over
    // browser-caching. The string changes on every update or full cache
    // flush, forcing browsers to load a new copy of the files, as the
    // URL changed.
    $query_string = $this->state->get('system.css_js_query_string', '0');

    // Defaults for LINK and STYLE elements.
    $link_element_defaults = [
      '#type' => 'html_tag',
      '#tag' => 'link',
      '#attributes' => [
        'rel' => 'stylesheet',
      ],
    ];

    foreach ($css_assets as $css_asset) {
      $element = $link_element_defaults;
      $element['#attributes']['media'] = $css_asset['media'];
      $element['#browsers'] = $css_asset['browsers'];

      switch ($css_asset['type']) {
        // For file items, output a LINK tag for file CSS assets.
        case 'file':
          $element['#attributes']['href'] = $this->fileUrlGenerator->generateString($css_asset['data']);
          // Only add the cache-busting query string if this isn't an aggregate
          // file.
          if (!isset($css_asset['preprocessed'])) {
            $query_string_separator = (strpos($css_asset['data'], '?') !== FALSE) ? '&' : '?';
            $element['#attributes']['href'] .= $query_string_separator . $query_string;
          }
          break;

        case 'external':
          $element['#attributes']['href'] = $css_asset['data'];
          break;

        default:
          throw new \Exception('Invalid CSS asset type.');
      }

      // Merge any additional attributes.
      if (!empty($css_asset['attributes'])) {
        $element['#attributes'] += $css_asset['attributes'];
      }

      $elements[] = $element;
    }

    return $elements;
  }

}
