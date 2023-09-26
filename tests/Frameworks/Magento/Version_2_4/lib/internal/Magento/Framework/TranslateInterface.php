<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework;

/**
 * @todo change this interface when i18n-related logic is moved to library
 *
 * @api
 */
interface TranslateInterface
{
    /**
     * Default translation string
     */
    const DEFAULT_STRING = 'Translate String';

    /**
     * Initialize translation data
     *
     * @param string|null $area
     * @param bool $forceReload
     * @return \Magento\Framework\TranslateInterface
     */
    public function loadData($area = null, $forceReload = false);

    /**
     * Retrieve translation data
     *
     * @return array
     */
    public function getData();

    /**
     * Retrieve locale
     *
     * @return string
     */
    public function getLocale();

    /**
     * Set locale
     *
     * @param string $locale
     * @return \Magento\Framework\TranslateInterface
     */
    public function setLocale($locale);

    /**
     * Retrieve theme code
     *
     * @return string
     */
    public function getTheme();
}
