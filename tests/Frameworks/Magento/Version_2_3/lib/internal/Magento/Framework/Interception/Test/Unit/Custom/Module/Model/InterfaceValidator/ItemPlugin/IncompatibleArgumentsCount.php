<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Interception\Test\Unit\Custom\Module\Model\InterfaceValidator\ItemPlugin;

class IncompatibleArgumentsCount
{
    /**
     * @param \Magento\Framework\Interception\Test\Unit\Custom\Module\Model\InterfaceValidator\Item $subject
     * @param string $name
     * @param string $surname
     * @return string
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeGetItem(
        \Magento\Framework\Interception\Test\Unit\Custom\Module\Model\InterfaceValidator\Item $subject,
        $name,
        $surname
    ) {
        return $name . $surname;
    }
}
