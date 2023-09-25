<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Swatches\Api;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeOptionLabelInterface;
use Magento\Eav\Model\AttributeRepository;
use Magento\Framework\DataObject;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Swatches\Model\ResourceModel\Swatch\Collection;
use Magento\Swatches\Model\ResourceModel\Swatch\CollectionFactory;
use Magento\Swatches\Model\Swatch;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\WebapiAbstract;

/**
 * Test product attribute option management API for swatch attribute type
 */
class ProductAttributeOptionManagementInterfaceTest extends WebapiAbstract
{
    private const ATTRIBUTE_CODE = 'select_attribute';
    private const SERVICE_NAME_UPDATE = 'catalogProductAttributeOptionUpdateV1';
    private const SERVICE_NAME = 'catalogProductAttributeOptionManagementV1';
    private const SERVICE_VERSION = 'V1';
    private const RESOURCE_PATH = '/V1/products/attributes';

    /**
     * Test add option to swatch attribute
     *
     * @dataProvider addDataProvider
     * @magentoApiDataFixture Magento/Catalog/Model/Product/Attribute/_files/select_attribute.php
     * @param array $data
     * @param array $payload
     * @param int $expectedSwatchType
     * @param string $expectedLabel
     * @param string $expectedValue
     *
     * @return void
     */
    public function testAdd(
        array $data,
        array $payload,
        int $expectedSwatchType,
        string $expectedLabel,
        string $expectedValue
    ): void {
        $objectManager = Bootstrap::getObjectManager();
        /** @var $attributeRepository AttributeRepository */
        $attributeRepository = $objectManager->get(AttributeRepository::class);
        /** @var $attribute Attribute */
        $attribute = $attributeRepository->get(ProductAttributeInterface::ENTITY_TYPE_CODE, self::ATTRIBUTE_CODE);
        $attribute->addData($data);
        $attributeRepository->save($attribute);
        $response = $this->_webApiCall(
            [
                'rest' => [
                    'resourcePath' => self::RESOURCE_PATH . '/' . self::ATTRIBUTE_CODE . '/options',
                    'httpMethod' => Request::HTTP_METHOD_POST,
                ],
                'soap' => [
                    'service' => self::SERVICE_NAME,
                    'serviceVersion' => self::SERVICE_VERSION,
                    'operation' => self::SERVICE_NAME . 'add',
                ],
            ],
            [
                'attributeCode' => self::ATTRIBUTE_CODE,
                'option' => $payload,
            ]
        );

        $this->assertNotNull($response);
        $optionId = (int)ltrim($response, 'id_');
        $swatch = $this->getSwatch($optionId);
        $this->assertEquals($expectedValue, $swatch->getValue());
        $this->assertEquals($expectedSwatchType, $swatch->getType());
        $options = $attribute->setStoreId(0)->getOptions();
        $this->assertCount(3, $options);
        $this->assertEquals($expectedLabel, $options[2]->getLabel());
    }

    /**
     * @magentoApiDataFixture Magento/Swatches/_files/text_swatch_attribute.php
     * @return void
     */
    public function testUpdate(): void
    {
        $testAttributeCode = 'test_configurable';
        $optionData = [
            AttributeOptionInterface::LABEL => 'Fixture Option Changed',
            AttributeOptionInterface::VALUE => 'option_value',
        ];

        $existOptionLabel = 'option 1';
        $existAttributeOption = $this->getAttributeOption($testAttributeCode, $existOptionLabel);
        $optionId = $existAttributeOption['value'];

        $response = $this->webApiCallAttributeOptions(
            $testAttributeCode,
            Request::HTTP_METHOD_PUT,
            'update',
            [
                'attributeCode' => $testAttributeCode,
                'optionId' => $optionId,
                'option' => $optionData,
            ],
            $optionId
        );
        $this->assertTrue($response);
        $this->assertNotNull(
            $this->getAttributeOption(
                $testAttributeCode,
                $optionData[AttributeOptionInterface::LABEL]
            )
        );
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function addDataProvider(): array
    {
        return [
            'visual swatch option with value' => [
                'data' => [
                    Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_VISUAL,
                    'option' => [

                    ]
                ],
                'payload' => [
                    AttributeOptionInterface::LABEL => 'Black',
                    AttributeOptionInterface::VALUE => '#000000',
                    AttributeOptionInterface::SORT_ORDER => 3,
                    AttributeOptionInterface::IS_DEFAULT => true,
                    AttributeOptionInterface::STORE_LABELS => [
                        [
                            AttributeOptionLabelInterface::LABEL => 'Noir',
                            AttributeOptionLabelInterface::STORE_ID => 1,
                        ],
                    ],
                ],
                'expectedSwatchType' => Swatch::SWATCH_TYPE_VISUAL_COLOR,
                'expectedLabel' => 'Black',
                'expectedValue' => '#000000',
            ],
            'visual swatch option without value' => [
                'data' => [
                    Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_VISUAL,
                    'option' => [

                    ]
                ],
                'payload' => [
                    AttributeOptionInterface::LABEL => 'Black',
                    AttributeOptionInterface::VALUE => '',
                    AttributeOptionInterface::SORT_ORDER => 3,
                    AttributeOptionInterface::IS_DEFAULT => true,
                    AttributeOptionInterface::STORE_LABELS => [
                        [
                            AttributeOptionLabelInterface::LABEL => 'Noir',
                            AttributeOptionLabelInterface::STORE_ID => 1,
                        ],
                    ],
                ],
                'expectedSwatchType' => Swatch::SWATCH_TYPE_EMPTY,
                'expectedLabel' => 'Black',
                'expectedValue' => '',
            ],
            'text swatch option with value' => [
                'data' => [
                    Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_TEXT,
                    'option' => [

                    ]
                ],
                'payload' => [
                    AttributeOptionInterface::LABEL => 'Small',
                    AttributeOptionInterface::VALUE => 'S',
                    AttributeOptionInterface::SORT_ORDER => 3,
                    AttributeOptionInterface::IS_DEFAULT => true,
                    AttributeOptionInterface::STORE_LABELS => [
                        [
                            AttributeOptionLabelInterface::LABEL => 'Petit',
                            AttributeOptionLabelInterface::STORE_ID => 1,
                        ],
                    ],
                ],
                'expectedSwatchType' => Swatch::SWATCH_TYPE_TEXTUAL,
                'expectedLabel' => 'Small',
                'expectedValue' => 'S',
            ],
            'text swatch option without value' => [
                'data' => [
                    Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_TEXT,
                    'option' => [

                    ]
                ],
                'payload' => [
                    AttributeOptionInterface::LABEL => 'Small',
                    AttributeOptionInterface::VALUE => '',
                    AttributeOptionInterface::SORT_ORDER => 3,
                    AttributeOptionInterface::IS_DEFAULT => true,
                    AttributeOptionInterface::STORE_LABELS => [
                        [
                            AttributeOptionLabelInterface::LABEL => 'Petit',
                            AttributeOptionLabelInterface::STORE_ID => 1,
                        ],
                    ],
                ],
                'expectedSwatchType' => Swatch::SWATCH_TYPE_TEXTUAL,
                'expectedLabel' => 'Small',
                'expectedValue' => '',
            ],
            'text swatch option with value - redeclare store ID 0 in store_labels' => [
                'data' => [
                    Swatch::SWATCH_INPUT_TYPE_KEY => Swatch::SWATCH_INPUT_TYPE_TEXT,
                    'option' => [

                    ]
                ],
                'payload' => [
                    AttributeOptionInterface::LABEL => 'Small',
                    AttributeOptionInterface::VALUE => 'S',
                    AttributeOptionInterface::SORT_ORDER => 3,
                    AttributeOptionInterface::IS_DEFAULT => true,
                    AttributeOptionInterface::STORE_LABELS => [
                        [
                            AttributeOptionLabelInterface::LABEL => 'Slim',
                            AttributeOptionLabelInterface::STORE_ID => 0,
                        ],
                    ],
                ],
                'expectedSwatchType' => Swatch::SWATCH_TYPE_TEXTUAL,
                'expectedLabel' => 'Slim',
                'expectedValue' => 'S',
            ],
        ];
    }

    /**
     * Get swatch model
     *
     * @param int $optionId
     * @return DataObject
     */
    private function getSwatch(int $optionId): DataObject
    {
        /** @var Collection $collection */
        $collection = Bootstrap::getObjectManager()->get(CollectionFactory::class)->create();
        $collection->addFieldToFilter('option_id', $optionId);
        $collection->setPageSize(1);

        return $collection->getFirstItem();
    }

    /**
     * Perform Web API call to the system under test
     *
     * @param string $attributeCode
     * @param string $httpMethod
     * @param string $soapMethod
     * @param array $arguments
     * @param null $storeCode
     * @param null $optionId
     * @return array|bool|float|int|string
     */
    private function webApiCallAttributeOptions(
        string $attributeCode,
        string $httpMethod,
        string $soapMethod,
        array $arguments = [],
        $optionId = null,
        $storeCode = null
    ) {
        $resourcePath = self::RESOURCE_PATH . "/{$attributeCode}/options";
        if ($optionId) {
            $resourcePath .= '/' . $optionId;
        }
        $serviceName = $soapMethod === 'update' ? self::SERVICE_NAME_UPDATE : self::SERVICE_NAME;
        $serviceInfo = [
            'rest' => [
                'resourcePath' => $resourcePath,
                'httpMethod' => $httpMethod,
            ],
            'soap' => [
                'service' => $serviceName,
                'serviceVersion' => self::SERVICE_VERSION,
                'operation' => $serviceName . $soapMethod,
            ],
        ];

        return $this->_webApiCall($serviceInfo, $arguments, null, $storeCode);
    }

    /**
     * Get Attribute options by attribute code
     *
     * @param string $testAttributeCode
     * @param string|null $storeCode
     * @return array|bool|float|int|string
     */
    private function getAttributeOptions(string $testAttributeCode, ?string $storeCode = null)
    {
        return $this->webApiCallAttributeOptions(
            $testAttributeCode,
            Request::HTTP_METHOD_GET,
            'getItems',
            ['attributeCode' => $testAttributeCode],
            null,
            $storeCode
        );
    }

    /**
     * Get Attribute option by attribute code
     *
     * @param string $attributeCode
     * @param string $optionLabel
     * @param string|null $storeCode
     * @return array|null
     */
    private function getAttributeOption(
        string $attributeCode,
        string $optionLabel,
        ?string $storeCode = null
    ): ?array {
        $attributeOptions = $this->getAttributeOptions($attributeCode, $storeCode);
        $option = null;
        /** @var array $attributeOption */
        foreach ($attributeOptions as $attributeOption) {
            if ($attributeOption['label'] === $optionLabel) {
                $option = $attributeOption;
                break;
            }
        }

        return $option;
    }
}
