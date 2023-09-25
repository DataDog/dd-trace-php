<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Eav\Test\Unit\Model\Attribute\Data;

use Magento\Eav\Model\Attribute\Data\Text;

class AbstractDataTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Eav\Model\Attribute\Data\AbstractData
     */
    protected $model;

    protected function setUp(): void
    {
        $timezoneMock = $this->createMock(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class);
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $localeResolverMock = $this->createMock(\Magento\Framework\Locale\ResolverInterface::class);
        $stringMock = $this->createMock(\Magento\Framework\Stdlib\StringUtils::class);

        /* testing abstract model through its child */
        $this->model = new Text($timezoneMock, $loggerMock, $localeResolverMock, $stringMock);
    }

    /**
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::getEntity
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::setEntity
     */
    public function testGetEntity()
    {
        $entityMock = $this->createMock(\Magento\Framework\Model\AbstractModel::class);
        $this->model->setEntity($entityMock);
        $this->assertEquals($entityMock, $this->model->getEntity());
    }

    /**
     *
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::getEntity
     */
    public function testGetEntityWhenEntityNotSet()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('Entity object is undefined');

        $this->model->getEntity();
    }

    /**
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::getExtractedData
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::setExtractedData
     *
     * @param string $index
     * @param mixed $expectedResult
     *
     * @dataProvider extractedDataDataProvider
     */
    public function testGetExtractedData($index, $expectedResult)
    {
        $extractedData = ['index' => 'value', 'otherIndex' => 'otherValue'];
        $this->model->setExtractedData($extractedData);
        $this->assertEquals($expectedResult, $this->model->getExtractedData($index));
    }

    /**
     * @return array
     */
    public function extractedDataDataProvider()
    {
        return [
            [
                'index' => 'index',
                'expectedResult' => 'value',
            ],
            [
                'index' => null,
                'expectedResult' => ['index' => 'value', 'otherIndex' => 'otherValue']
            ],
            [
                'index' => 'customIndex',
                'expectedResult' => null
            ]
        ];
    }

    /**
     * @covers \Magento\Eav\Model\Attribute\Data\AbstractData::_getRequestValue
     *
     * @param string $requestScope
     * @param string $value
     * @param string $expectedResult
     * @param array $params
     * @param bool $requestScopeOnly
     * @param string|null $filter
     * @dataProvider getRequestValueDataProvider
     */
    public function testGetRequestValue($requestScope, $value, $params, $requestScopeOnly, $expectedResult, $filter)
    {
        $requestMock = $this->createPartialMock(\Magento\Framework\App\Request\Http::class, ['getParams', 'getParam']);
        $requestMock->expects($this->any())->method('getParam')->willReturnMap([
            ['attributeCode', false, $value],
            [$requestScope, $value],
        ]);
        $requestMock->expects($this->any())->method('getParams')->willReturn($params);

        $attributeMock = $this->createPartialMock(
            \Magento\Eav\Model\Attribute::class,
            ['getInputFilter', 'getAttributeCode']
        );
        $attributeMock->expects($this->any())->method('getAttributeCode')->willReturn('attributeCode');
        if ($filter) {
            $attributeMock->expects($this->any())->method('getInputFilter')->willReturn($filter);
        }

        $this->model->setAttribute($attributeMock);
        $this->model->setRequestScope($requestScope);
        $this->model->setRequestScopeOnly($requestScopeOnly);
        $this->assertEquals($expectedResult, $this->model->extractValue($requestMock));
    }

    /**
     * @return array
     */
    public function getRequestValueDataProvider()
    {
        return [
            [
                'requestScope' => false,
                'value' => 'value',
                'params' => [],
                'requestScopeOnly' => true,
                'expectedResult' => 'value',
                'filter' => null,
            ],
            [
                'requestScope' => 'scope/scope',
                'value' => 'value',
                'params' => ['scope' => ['scope' => ['attributeCode' => 'data']]],
                'requestScopeOnly' => true,
                'expectedResult' => 'data',
                'filter' => null,
            ],
            [
                'requestScope' => 'scope/scope',
                'value' => 'value',
                'params' => ['scope' => ['scope' => []]],
                'requestScopeOnly' => true,
                'expectedResult' => false,
                'filter' => null,
            ],
            [
                'requestScope' => 'scope/scope',
                'value' => 'value',
                'params' => ['scope'],
                'requestScopeOnly' => true,
                'expectedResult' => false,
                'filter' => null,
            ],
            [
                'requestScope' => 'scope',
                'value' => 'value',
                'params' => ['otherScope' => 1],
                'requestScopeOnly' => true,
                'expectedResult' => false,
                'filter' => null,
            ],
            [
                'requestScope' => 'scope',
                'value' => 'value',
                'params' => ['otherScope' => 1],
                'requestScopeOnly' => false,
                'expectedResult' => 'value',
                'filter' => null,
            ],
            [
                'requestScope' => 'scope',
                'value' => '1970-01-01',
                'params' => [],
                'requestScopeOnly' => false,
                'expectedResult' => '1970-01-01',
                'filter' => 'date'
            ]
        ];
    }
}
