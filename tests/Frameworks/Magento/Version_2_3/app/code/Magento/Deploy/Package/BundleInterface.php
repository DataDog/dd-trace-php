<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Deploy\Package;

/**
 * Bundle Interface
 */
interface BundleInterface
{
    /**
     * Path relative to package directory where bundle files should be created
     */
    const BUNDLE_JS_DIR = 'js/bundle';

    /**
     * Add file that can be bundled
     *
     * @param string $filePath
     * @param string $sourcePath
     * @param string $contentType
     * @return bool true on success
     */
    public function addFile($filePath, $sourcePath, $contentType);

    /**
     * Flushes all files added to appropriate bundle
     *
     * @return bool true on success
     */
    public function flush();

    /**
     * Delete all bundles
     *
     * @return bool true on success
     */
    public function clear();
}
