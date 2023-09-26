<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Multishipping\Controller\Checkout\Address;

class EditShippingPost extends \Magento\Multishipping\Controller\Checkout\Address
{
    /**
     * @return void
     */
    public function execute()
    {
        if ($addressId = $this->getRequest()->getParam('id')) {
            $this->_objectManager->create(
                \Magento\Multishipping\Model\Checkout\Type\Multishipping::class
            )->updateQuoteCustomerShippingAddress(
                $addressId
            );
        }
        $this->_redirect('*/checkout/shipping');
    }
}
