<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Template\Html;

/**
 * HTML minifier
 *
 * @api
 * @since 100.0.2
 */
interface MinifierInterface
{
    /**
     * Return path to minified template file, or minify if file not exist
     *
     * @param string $file
     * @return string
     */
    public function getMinified($file);

    /**
     * Return path to minified template file
     *
     * @param string $file
     * @return string
     */
    public function getPathToMinified($file);

    /**
     * Minify template file
     *
     * @param string $file
     * @return void
     */
    public function minify($file);
}
