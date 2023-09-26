<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Paypal\Block\Adminhtml\System\Config\Field\Enable;

/**
 * Class Bml
 * @deprecated 100.3.1
 * "Enable PayPal Credit" setting was removed. Please @see "Disable Funding Options"
 */
class BmlApi extends AbstractEnable
{
    /**
     * Getting the name of a UI attribute
     *
     * @return string
     */
    protected function getDataAttributeName()
    {
        return 'bml-api';
    }
}
