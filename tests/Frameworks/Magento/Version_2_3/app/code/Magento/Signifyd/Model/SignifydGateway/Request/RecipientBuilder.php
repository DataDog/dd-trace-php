<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Model\SignifydGateway\Request;

use Magento\Sales\Model\Order;

/**
 * Prepare data related to person or organization receiving the items purchased
 *
 * @deprecated 100.3.5 Starting from Magento 2.3.5 Signifyd core integration is deprecated in favor of
 * official Signifyd integration available on the marketplace
 */
class RecipientBuilder
{
    /**
     * @var AddressBuilder
     */
    private $addressBuilder;

    /**
     * @param AddressBuilder $addressBuilder
     */
    public function __construct(
        AddressBuilder $addressBuilder
    ) {
        $this->addressBuilder = $addressBuilder;
    }

    /**
     * Returns recipient data params based on shipping address
     *
     * @param Order $order
     * @return array
     */
    public function build(Order $order)
    {
        $result = [];
        $address = $order->getShippingAddress();
        if ($address === null) {
            return $result;
        }

        $result = [
            'recipient' => [
                'fullName' => $address->getName(),
                'confirmationEmail' =>  $address->getEmail(),
                'confirmationPhone' => $address->getTelephone(),
                'organization' => $address->getCompany(),
                'deliveryAddress' => $this->addressBuilder->build($address)
            ]
        ];

        return $result;
    }
}
