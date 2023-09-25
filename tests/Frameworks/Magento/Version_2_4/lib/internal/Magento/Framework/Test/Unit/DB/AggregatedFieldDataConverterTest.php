<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Test\Unit\DB;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\AggregatedFieldDataConverter;
use Magento\Framework\DB\FieldDataConverter;
use Magento\Framework\DB\FieldDataConverterFactory;
use Magento\Framework\DB\FieldToConvert;
use Magento\Framework\DB\Select\QueryModifierInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AggregatedFieldDataConverterTest extends TestCase
{
    public function testConvert()
    {
        $connection = $this->getMockBuilder(AdapterInterface::class)
            ->getMock();
        $queryModifier = $this->getMockBuilder(QueryModifierInterface::class)
            ->getMock();
        $fields = [
            new FieldToConvert(
                'ClassOne',
                'table_1',
                'id_1',
                'field_1'
            ),
            new FieldToConvert(
                'ClassTwo',
                'table_2',
                'id_2',
                'field_2',
                $queryModifier
            ),
        ];
        $fieldConverterOne = $this->getMockBuilder(FieldDataConverter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $fieldConverterTwo = clone $fieldConverterOne;
        $fieldConverterFactory = $this->createFieldConverterFactory(
            [
                ['ClassOne', $fieldConverterOne],
                ['ClassTwo', $fieldConverterTwo],
            ]
        );

        $this->assertCallsDelegation($connection, $fieldConverterOne, $fieldConverterTwo, $queryModifier);
        $object = new AggregatedFieldDataConverter($fieldConverterFactory);
        $object->convert($fields, $connection);
    }

    /**
     * @param array $returnValuesMap
     * @return MockObject
     */
    private function createFieldConverterFactory(array $returnValuesMap)
    {
        $fieldConverterFactory = $this->getMockBuilder(FieldDataConverterFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $fieldConverterFactory->expects($this->any())
            ->method('create')
            ->willReturnMap($returnValuesMap);
        return $fieldConverterFactory;
    }

    /**
     * Assert that correct methods with correct arguments are called during delegation of the action
     *
     * @param $connection
     * @param MockObject $fieldConverterOne
     * @param MockObject $fieldConverterTwo
     * @param $queryModifier
     */
    private function assertCallsDelegation(
        $connection,
        MockObject $fieldConverterOne,
        MockObject $fieldConverterTwo,
        $queryModifier
    ) {
        $fieldConverterOne->expects($this->once())
            ->method('convert')
            ->with($connection, 'table_1', 'id_1', 'field_1', null);
        $fieldConverterTwo->expects($this->once())
            ->method('convert')
            ->with($connection, 'table_2', 'id_2', 'field_2', $queryModifier);
    }
}
