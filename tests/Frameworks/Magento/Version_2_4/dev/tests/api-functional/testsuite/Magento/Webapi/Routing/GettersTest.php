<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Webapi\Routing;

use Magento\TestModule5\Service\V1\Entity\AllSoapAndRest;

class GettersTest extends \Magento\Webapi\Routing\BaseService
{
    /**
     * @var string
     */
    protected $_version;

    /**
     * @var string
     */
    protected $_restResourcePath;

    /**
     * @var string
     */
    protected $_soapService = 'testModule5AllSoapAndRest';

    protected function setUp(): void
    {
        $this->_version = 'V1';
        $this->_soapService = "testModule5AllSoapAndRest{$this->_version}";
        $this->_restResourcePath = "/{$this->_version}/TestModule5/";
    }

    public function testGetters()
    {
        $itemId = 1;
        $serviceInfo = [
            'rest' => [
                'resourcePath' => $this->_restResourcePath . $itemId,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET,
            ],
            'soap' => [
                'service' => $this->_soapService,
                'operation' => $this->_soapService . 'Item',
            ],
        ];
        $requestData = [AllSoapAndRest::ID => $itemId];
        $item = $this->_webApiCall($serviceInfo, $requestData);
        $this->assertEquals($itemId, $item[AllSoapAndRest::ID], 'Item was retrieved unsuccessfully');
        $isEnabled = isset($item[AllSoapAndRest::ENABLED]) && $item[AllSoapAndRest::ENABLED] === true;
        $this->assertTrue($isEnabled, 'Getter with "is" prefix is processed incorrectly.');
        $hasOrder = isset($item[AllSoapAndRest::HAS_ORDERS]) && $item[AllSoapAndRest::HAS_ORDERS] === true;
        $this->assertTrue($hasOrder, 'Getter with "has" prefix is processed incorrectly.');
    }
}
