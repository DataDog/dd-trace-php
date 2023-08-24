<?php

namespace Drupal\Core\Render\MainContent;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenDialogCommand;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default main content renderer for dialog requests.
 */
class DialogRenderer implements MainContentRendererInterface {

  /**
   * The title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected $titleResolver;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new DialogRenderer.
   *
   * @param \Drupal\Core\Controller\TitleResolverInterface $title_resolver
   *   The title resolver.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(TitleResolverInterface $title_resolver, RendererInterface $renderer = NULL) {
    $this->titleResolver = $title_resolver;
    if ($renderer === NULL) {
      @trigger_error('The renderer service must be passed to ' . __METHOD__ . ' and will be required before Drupal 9.0.0. See https://www.drupal.org/node/3009400', E_USER_DEPRECATED);
      $renderer = \Drupal::service('renderer');
    }
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public function renderResponse(array $main_content, Request $request, RouteMatchInterface $route_match) {
    $response = new AjaxResponse();

    // First render the main content, because it might provide a title.
    $content = $this->renderer->renderRoot($main_content);

    // Attach the library necessary for using the OpenDialogCommand and set the
    // attachments for this Ajax response.
    $main_content['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $response->setAttachments($main_content['#attached']);

    // Determine the title: use the title provided by the main content if any,
    // otherwise get it from the routing information.
    $title = isset($main_content['#title']) ? $main_content['#title'] : $this->titleResolver->getTitle($request, $route_match->getRouteObject());

    // Determine the dialog options and the target for the OpenDialogCommand.
    $options = $request->request->get('dialogOptions', []);
    $target = $this->determineTargetSelector($options, $route_match);

    $response->addCommand(new OpenDialogCommand($target, $title, $content, $options));
    return $response;
  }

  /**
   * Determine the target selector for the OpenDialogCommand.
   *
   * @param array &$options
   *   The 'target' option, if set, is used, and then removed from $options.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   When no 'target' option is set in $options, $route_match is used instead
   *   to determine the target.
   *
   * @return string
   *   The target selector.
   */
  protected function determineTargetSelector(array &$options, RouteMatchInterface $route_match) {
    // Generate the target wrapper for the dialog.
    if (isset($options['target'])) {
      // If the target was nominated in the incoming options, use that.
      $target = $options['target'];
      // Ensure the target includes the #.
      if (substr($target, 0, 1) != '#') {
        $target = '#' . $target;
      }
      // This shouldn't be passed on to jQuery.ui.dialog.
      unset($options['target']);
    }
    else {
      // Generate a target based on the route id.
      $route_name = $route_match->getRouteName();
      $target = '#' . Html::getUniqueId("drupal-dialog-$route_name");
    }
    return $target;
  }

}
