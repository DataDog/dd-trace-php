<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\View\Layout;

/**
 * Interface \Magento\Framework\View\Layout\ReaderInterface
 *
 */
interface ReaderInterface
{
    /**
     * Read children elements structure and fill scheduled structure
     *
     * @param Reader\Context $readerContext
     * @param Element $element
     * @return $this
     */
    public function interpret(Reader\Context $readerContext, Element $element);

    /**
     * Get nodes types that current reader is support
     *
     * @return string[]
     */
    public function getSupportedNodes();
}
