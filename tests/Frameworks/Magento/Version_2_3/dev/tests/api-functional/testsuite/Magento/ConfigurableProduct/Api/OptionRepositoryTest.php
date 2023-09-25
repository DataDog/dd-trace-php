<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ConfigurableProduct\Api;

use Magento\TestFramework\Helper\Bootstrap;
use Magento\Eav\Api\AttributeRepositoryInterface;

/**
 * Class OptionRepositoryTest for testing ConfigurableProductoption integration
 */
class OptionRepositoryTest extends \Magento\TestFramework\TestCase\WebapiAbstract
{
    const SERVICE_NAME = 'configurableProductOptionRepositoryV1';
    const SERVICE_VERSION = 'V1';
    const RESOURCE_PATH = '/V1/configurable-products';

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testGet()
    {
        $productSku = 'configurable';

        $options = $this->getList($productSku);
        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        foreach ($options as $option) {
            /** @var array $result */
            $result = $this->get($productSku, $option['id']);

            $this->assertIsArray($result);
            $this->assertNotEmpty($result);

            $this->assertArrayHasKey('id', $result);
            $this->assertEquals($option['id'], $result['id']);

            $this->assertArrayHasKey('attribute_id', $result);
            $this->assertEquals($option['attribute_id'], $result['attribute_id']);

            $this->assertArrayHasKey('label', $result);
            $this->assertEquals($option['label'], $result['label']);

            $this->assertArrayHasKey('values', $result);
            $this->assertIsArray($result['values']);
            $this->assertEquals($option['values'], $result['values']);
        }
    }

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testGetList()
    {
        $productSku = 'configurable';

        /** @var array $result */
        $result = $this->getList($productSku);

        $this->assertNotEmpty($result);
        $this->assertIsArray($result);
        $this->assertArrayHasKey(0, $result);

        $option = $result[0];

        $this->assertNotEmpty($option);
        $this->assertIsArray($option);

        $this->assertArrayHasKey('id', $option);
        $this->assertArrayHasKey('label', $option);
        $this->assertEquals($option['label'], 'Test Configurable');

        $this->assertArrayHasKey('values', $option);
        $this->assertIsArray($option);
        $this->assertNotEmpty($option);

        $this->assertCount(2, $option['values']);

        foreach ($option['values'] as $value) {
            $this->assertIsArray($value);
            $this->assertNotEmpty($value);

            $this->assertArrayHasKey('value_index', $value);
        }
    }

    /**
     */
    public function testGetUndefinedProduct()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(
            'The product that was requested doesn\'t exist. Verify the product and try again.'
        );

        $productSku = 'product_not_exist';
        $this->getList($productSku);
    }

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testGetUndefinedOption()
    {
        $expectedMessage = 'The "%1" entity that was requested doesn\'t exist. Verify the entity and try again.';
        $productSku = 'configurable';
        $attributeId = -42;
        try {
            $this->get($productSku, $attributeId);
        } catch (\SoapFault $e) {
            $this->assertStringContainsString(
                $expectedMessage,
                $e->getMessage(),
                'SoapFault does not contain expected message.'
            );
        } catch (\Exception $e) {
            $errorObj = $this->processRestExceptionResult($e);
            $this->assertEquals($expectedMessage, $errorObj['message']);
            $this->assertEquals([$attributeId], $errorObj['parameters']);
        }
    }

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testDelete()
    {
        $productSku = 'configurable';

        $optionList = $this->getList($productSku);
        $optionId = $optionList[0]['id'];
        $resultRemove = $this->delete($productSku, $optionId);
        $optionListRemoved = $this->getList($productSku);

        $this->assertTrue($resultRemove);
        $this->assertEquals(count($optionList) - 1, count($optionListRemoved));
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/configurable_attribute.php
     */
    public function testAdd()
    {
        /** @var AttributeRepositoryInterface $attributeRepository */
        $attributeRepository = Bootstrap::getObjectManager()->create(AttributeRepositoryInterface::class);

        /** @var \Magento\Eav\Api\Data\AttributeInterface $attribute */
        $attribute = $attributeRepository->get('catalog_product', 'test_configurable');

        $productSku = 'simple';
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options',
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_POST
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save'
            ]
        ];
        $option = [
            'attribute_id' => $attribute->getAttributeId(),
            'label' => 'Test',
            'values' => [
                [
                    'value_index' => 1,
                ]
            ],
        ];
        /** @var int $result */
        $result = $this->_webApiCall($serviceInfo, ['sku' => $productSku, 'option' => $option]);
        $this->assertGreaterThan(0, $result);
    }

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testUpdate()
    {
        $productSku = 'configurable';
        $configurableAttribute = $this->getConfigurableAttribute($productSku);
        $optionId = $configurableAttribute[0]['id'];
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options' . '/' . $optionId,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_PUT
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save'
            ]
        ];

        $requestBody = [
            'option' => [
                'label' => 'Update Test Configurable',
            ]
        ];

        if (TESTS_WEB_API_ADAPTER == self::ADAPTER_SOAP) {
            $requestBody['sku'] = $productSku;
            $requestBody['option']['id'] = $optionId;
        }

        $result = $this->_webApiCall($serviceInfo, $requestBody);
        $this->assertGreaterThan(0, $result);
        $configurableAttribute = $this->getConfigurableAttribute($productSku);
        $this->assertEquals($requestBody['option']['label'], $configurableAttribute[0]['label']);
    }

    /**
     * @magentoApiDataFixture Magento/ConfigurableProduct/_files/product_configurable.php
     */
    public function testUpdateWithoutOptionId()
    {
        $productSku = 'configurable';
        /** @var AttributeRepositoryInterface $attributeRepository */

        $attributeRepository = Bootstrap::getObjectManager()->create(AttributeRepositoryInterface::class);

        /** @var \Magento\Eav\Api\Data\AttributeInterface $attribute */
        $attribute = $attributeRepository->get('catalog_product', 'test_configurable');

        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options',
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_POST
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'Save'
            ]
        ];

        $option = [
            'attribute_id' => $attribute->getAttributeId(),
            'label' => 'Update Test Configurable with sku and attribute_id',
            'values' => [
                [
                    'value_index' => 1,
                ]
            ],
        ];

        $result = $this->_webApiCall($serviceInfo, ['sku' => $productSku, 'option' => $option]);
        $this->assertGreaterThan(0, $result);
        $configurableAttribute = $this->getConfigurableAttribute($productSku);
        $this->assertEquals($option['label'], $configurableAttribute[0]['label']);
    }

    /**
     * @param string $productSku
     * @return array
     */
    protected function getConfigurableAttribute($productSku)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options/all',
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'GetList'
            ]
        ];
        return $this->_webApiCall($serviceInfo, ['sku' => $productSku]);
    }

    /**
     * @param string $productSku
     * @param int $optionId
     * @return bool
     */
    private function delete($productSku, $optionId)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options/' . $optionId,
                'httpMethod' => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_DELETE
            ],
            'soap' => [
                'service' => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => self::SERVICE_NAME . 'DeleteById'
            ]
        ];
        return $this->_webApiCall($serviceInfo, ['sku' => $productSku, 'id' => $optionId]);
    }

    /**
     * @param $productSku
     * @param $optionId
     * @return array
     */
    protected function get($productSku, $optionId)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options/' . $optionId,
                'httpMethod'   => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET
            ],
            'soap' => [
                'service'        => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation'      => self::SERVICE_NAME . 'Get'
            ]
        ];
        return $this->_webApiCall($serviceInfo, ['sku' => $productSku, 'id' => $optionId]);
    }

    /**
     * @param $productSku
     * @return array
     */
    protected function getList($productSku)
    {
        $serviceInfo = [
            'rest' => [
                'resourcePath' => self::RESOURCE_PATH . '/' . $productSku . '/options/all',
                'httpMethod'   => \Magento\Framework\Webapi\Rest\Request::HTTP_METHOD_GET
            ],
            'soap' => [
                'service'        => self::SERVICE_NAME,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation'      => self::SERVICE_NAME . 'GetList'
            ]
        ];
        return $this->_webApiCall($serviceInfo, ['sku' => $productSku]);
    }
}
