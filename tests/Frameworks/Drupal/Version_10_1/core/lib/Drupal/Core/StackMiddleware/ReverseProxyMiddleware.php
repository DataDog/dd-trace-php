<?php

namespace Drupal\Core\StackMiddleware;

use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Provides support for reverse proxies.
 */
class ReverseProxyMiddleware implements HttpKernelInterface {

  /**
   * The decorated kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The site settings.
   *
   * @var \Drupal\Core\Site\Settings
   */
  protected $settings;

  /**
   * Constructs a ReverseProxyMiddleware object.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public function __construct(HttpKernelInterface $http_kernel, Settings $settings) {
    $this->httpKernel = $http_kernel;
    $this->settings = $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    // Initialize proxy settings.
    static::setSettingsOnRequest($request, $this->settings);
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Sets reverse proxy settings on Request object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   A Request instance.
   * @param \Drupal\Core\Site\Settings $settings
   *   The site settings.
   */
  public static function setSettingsOnRequest(Request $request, Settings $settings) {
    // Initialize proxy settings.
    if ($settings->get('reverse_proxy', FALSE)) {
      $proxies = $settings->get('reverse_proxy_addresses', []);
      if (count($proxies) > 0) {
        // Set the default value. This is the most relaxed setting possible and
        // not recommended for production.
        $trusted_header_set = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_FORWARDED;

        $request::setTrustedProxies(
          $proxies,
          $settings->get('reverse_proxy_trusted_headers', $trusted_header_set)
        );
      }
    }
  }

}
