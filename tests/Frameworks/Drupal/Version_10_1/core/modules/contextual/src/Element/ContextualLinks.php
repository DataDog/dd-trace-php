<?php

namespace Drupal\contextual\Element;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Render\Element\RenderElement;
use Drupal\Core\Url;

/**
 * Provides a contextual_links element.
 *
 * @RenderElement("contextual_links")
 */
class ContextualLinks extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = static::class;
    return [
      '#pre_render' => [
        [$class, 'preRenderLinks'],
      ],
      '#theme' => 'links__contextual',
      '#links' => [],
      '#attributes' => ['class' => ['contextual-links']],
      '#attached' => [
        'library' => [
          'contextual/drupal.contextual-links',
        ],
      ],
    ];
  }

  /**
   * Pre-render callback: Builds a renderable array for contextual links.
   *
   * @param array $element
   *   A renderable array containing a #contextual_links property, which is a
   *   keyed array. Each key is the name of the group of contextual links to
   *   render (based on the 'group' key in the *.links.contextual.yml files for
   *   all enabled modules). The value contains an associative array containing
   *   the following keys:
   *   - route_parameters: The route parameters passed to the URL generator.
   *   - metadata: Any additional data needed in order to alter the link.
   *   @code
   *     array('#contextual_links' => array(
   *       'block' => array(
   *         'route_parameters' => array('block' => 'system.menu-tools'),
   *       ),
   *       'menu' => array(
   *         'route_parameters' => array('menu' => 'tools'),
   *       ),
   *     ))
   *   @endcode
   *
   * @return array
   *   A renderable array representing contextual links.
   */
  public static function preRenderLinks(array $element) {
    // Retrieve contextual menu links.
    $items = [];

    $contextual_links_manager = static::contextualLinkManager();

    foreach ($element['#contextual_links'] as $group => $args) {
      $args += [
        'route_parameters' => [],
        'metadata' => [],
      ];
      $items += $contextual_links_manager->getContextualLinksArrayByGroup($group, $args['route_parameters'], $args['metadata']);
    }

    uasort($items, [SortArray::class, 'sortByWeightElement']);

    // Transform contextual links into parameters suitable for links.html.twig.
    $links = [];
    foreach ($items as $class => $item) {
      $class = Html::getClass($class);
      $links[$class] = [
        'title' => $item['title'],
        'url' => Url::fromRoute($item['route_name'] ?? '', $item['route_parameters'] ?? [], $item['localized_options']),
      ];
    }
    $element['#links'] = $links;

    // Allow modules to alter the renderable contextual links element.
    static::moduleHandler()->alter('contextual_links_view', $element, $items);

    // If there are no links, tell \Drupal::service('renderer')->render() to
    // abort rendering.
    if (empty($element['#links'])) {
      $element['#printed'] = TRUE;
    }

    return $element;
  }

  /**
   * Wraps the contextual link manager.
   *
   * @return \Drupal\Core\Menu\ContextualLinkManager
   */
  protected static function contextualLinkManager() {
    return \Drupal::service('plugin.manager.menu.contextual_link');
  }

  /**
   * Wraps the module handler.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected static function moduleHandler() {
    return \Drupal::moduleHandler();
  }

}
