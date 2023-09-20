<?php
/**
 * Application interface
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework;

/**
 * Different magento entry points call corresponding applications after platform is bootstrapped.
 * index.php in webroot calls HTTP application (implementation of this interface) as it is responsible for web requests.
 * cron.php entry point calls cron application
 * Implementations of this interface should implement application type specific initialization.
 *
 * @api
 * @since 100.0.2
 */
interface AppInterface
{
    /**
     * Default application locale
     */
    const DISTRO_LOCALE_CODE = 'en_US';

    /**
     * Launch application
     *
     * @return \Magento\Framework\App\ResponseInterface
     */
    public function launch();

    /**
     * Ability to handle exceptions that may have occurred during bootstrap and launch
     *
     * Return values:
     * - true: exception has been handled, no additional action is needed
     * - false: exception has not been handled - pass the control to Bootstrap
     *
     * @param App\Bootstrap $bootstrap
     * @param \Exception $exception
     * @return bool
     */
    public function catchException(App\Bootstrap $bootstrap, \Exception $exception);
}
