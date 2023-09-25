<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Service\V1;

use Magento\Sales\Api\Data\OrderAddressInterface as OrderAddress;
use Magento\TestFramework\TestCase\WebapiAbstract;

/**
 * Test for address update
 */
class OrderAddressUpdateTest extends WebapiAbstract
{
    const SERVICE_VERSION = 'V1';

    const SERVICE_NAME = 'salesOrderAddressRepositoryV1';

    /**
     * @magentoApiDataFixture Magento/Sales/_files/order.php
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function testOrderAddressUpdate()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        /** @var \Magento\Sales\Model\Order $order */
        $order = $objectManager->get(\Magento\Sales\Model\Order::class)->loadByIncrementId('100000001');

        $address = [
            OrderAddress::REGION => 'CA',
            OrderAddress::POSTCODE => '11111',
            OrderAddress::LASTNAME => 'lastname',
            OrderAddress::STREET => ['street'],
            OrderAddress::CITY => 'city',
            OrderAddress::EMAIL => 'email@email.com',
            OrderAddress::COMPANY => 'company',
            OrderAddress::TELEPHONE => 't123456789',
            OrderAddress::COUNTRY_ID => 'US',
            OrderAddress::FIRSTNAME => 'firstname',
            OrderAddress::ADDRESS_TYPE => 'billing',
            OrderAddress::PARENT_ID => $order->getId(),
            OrderAddress::ENTITY_ID => $order->getBillingAddressId(),
            OrderAddress::CUSTOMER_ADDRESS_ID => null,
            OrderAddress::CUSTOMER_ID => null,
            OrderAddress::FAX => null,
            OrderAddress::MIDDLENAME => null,
            OrderAddress::PREFIX => null,
            OrderAddress::REGION_ID => null,
            OrderAddress::SUFFIX => null,
            OrderAddress::VAT_ID => null,
            OrderAddress::VAT_IS_VALID => null,
            OrderAddress::VAT_REQUEST_DATE => null,
            OrderAddress::VAT_REQUEST_ID => null,
            OrderAddress::VAT_REQUEST_SUCCESS => null,
        ];
        $requestData = ['entity' => $address];

        $serviceInfo = [
            'rest' => [
                'resourcePath' => '/V1/orders/' . $order->getId(),
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_PUT,
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'save',
            ],
        ];
        $result = $this->_webApiCall($serviceInfo, $requestData);
        $this->assertGreaterThan(1, count($result));

        /** @var \Magento\Sales\Model\Order $actualOrder */
        $actualOrder = $objectManager->get(\Magento\Sales\Model\Order::class)->load($order->getId());
        $billingAddress = $actualOrder->getBillingAddress();

        $validate = [
            OrderAddress::REGION => 'CA',
            OrderAddress::POSTCODE => '11111',
            OrderAddress::LASTNAME => 'lastname',
            OrderAddress::STREET => 'street',
            OrderAddress::CITY => 'city',
            OrderAddress::EMAIL => 'email@email.com',
            OrderAddress::COMPANY => 'company',
            OrderAddress::TELEPHONE => 't123456789',
            OrderAddress::COUNTRY_ID => 'US',
            OrderAddress::FIRSTNAME => 'firstname',
            OrderAddress::ADDRESS_TYPE => 'billing',
            OrderAddress::PARENT_ID => $order->getId(),
            OrderAddress::ENTITY_ID => $order->getBillingAddressId(),
        ];
        foreach ($validate as $key => $field) {
            $this->assertEquals($validate[$key], $billingAddress->getData($key));
        }
    }
}
