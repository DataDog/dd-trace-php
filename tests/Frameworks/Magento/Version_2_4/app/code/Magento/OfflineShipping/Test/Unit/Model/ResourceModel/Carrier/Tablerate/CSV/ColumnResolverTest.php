<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\OfflineShipping\Test\Unit\Model\ResourceModel\Carrier\Tablerate\CSV;

use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CSV\ColumnNotFoundException;
use Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CSV\ColumnResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CSV\ColumnResolver
 */
class ColumnResolverTest extends TestCase
{
    const CUSTOM_FIELD = 'custom_field';

    // phpstan:ignore
    private $values = [
        ColumnResolver::COLUMN_COUNTRY => 'country value',
        ColumnResolver::COLUMN_REGION => 'region value',
        ColumnResolver::COLUMN_ZIP => 'zip_value',
        ColumnResolver::COLUMN_WEIGHT => 'weight_value',
        ColumnResolver::COLUMN_WEIGHT_DESTINATION => 'weight_destination_value',
        ColumnResolver::COLUMN_PRICE => 'price_value',
        self::CUSTOM_FIELD => 'custom_value',
    ];

    /**
     * @param $column
     * @param $expectedValue
     * @throws ColumnNotFoundException
     * @dataProvider getColumnValueDataProvider
     */
    public function testGetColumnValueByPosition($column, $expectedValue)
    {
        $headers = array_keys($this->values);
        $headers = [];
        $columnResolver = $this->createColumnResolver($headers);
        $values = array_values($this->values);
        $result = $columnResolver->getColumnValue($column, $values);
        $this->assertEquals($expectedValue, $result);
    }

    /**
     * @param array $headers
     * @param array $columns
     * @return ColumnResolver
     */
    private function createColumnResolver(array $headers = [], array $columns = [])
    {
        return new ColumnResolver($headers, $columns);
    }

    /**
     * @return void
     * @dataProvider getColumnValueWithCustomHeaderDataProvider
     */
    public function testGetColumnValueByHeader($column, $expectedValue)
    {
        $reversedValues = array_reverse($this->values);
        $headers = array_keys($reversedValues);
        $values = array_values($reversedValues);
        $columnResolver = $this->createColumnResolver($headers);
        $result = $columnResolver->getColumnValue($column, $values);
        $this->assertEquals($expectedValue, $result);
    }

    /**
     * @return array
     */
    public function getColumnValueDataProvider()
    {
        return [
            ColumnResolver::COLUMN_COUNTRY => [
                ColumnResolver::COLUMN_COUNTRY,
                $this->values[ColumnResolver::COLUMN_COUNTRY],
            ],
            ColumnResolver::COLUMN_REGION => [
                ColumnResolver::COLUMN_REGION,
                $this->values[ColumnResolver::COLUMN_REGION],
            ],
            ColumnResolver::COLUMN_ZIP => [
                ColumnResolver::COLUMN_ZIP,
                $this->values[ColumnResolver::COLUMN_ZIP],
            ],
            // phpstan:ignore
            ColumnResolver::COLUMN_WEIGHT => [
                ColumnResolver::COLUMN_WEIGHT,
                $this->values[ColumnResolver::COLUMN_WEIGHT],
            ],
            ColumnResolver::COLUMN_WEIGHT_DESTINATION => [
                ColumnResolver::COLUMN_WEIGHT_DESTINATION,
                $this->values[ColumnResolver::COLUMN_WEIGHT_DESTINATION],
            ],
            ColumnResolver::COLUMN_PRICE => [
                ColumnResolver::COLUMN_PRICE,
                $this->values[ColumnResolver::COLUMN_PRICE],
            ]
        ];
    }

    /**
     * @return array
     */
    public function getColumnValueWithCustomHeaderDataProvider()
    {
        $customField = [
            self::CUSTOM_FIELD => [
                self::CUSTOM_FIELD,
                $this->values[self::CUSTOM_FIELD],
            ],
        ];
        return array_merge($this->getColumnValueDataProvider(), $customField);
    }

    /**
     * @throws ColumnNotFoundException
     */
    public function testGetColumnValueWithUnknownColumn()
    {
        $this->expectException(
            'Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CSV\ColumnNotFoundException'
        );
        $this->expectExceptionMessage('Requested column "custom_field" cannot be resolved');
        $columnResolver = $this->createColumnResolver();
        $values = array_values($this->values);
        $columnResolver->getColumnValue(self::CUSTOM_FIELD, $values);
    }

    /**
     * @throws ColumnNotFoundException
     */
    public function testGetColumnValueWithUndefinedValue()
    {
        $this->expectException(
            'Magento\OfflineShipping\Model\ResourceModel\Carrier\Tablerate\CSV\ColumnNotFoundException'
        );
        $this->expectExceptionMessage('Column "new_custom_column" not found');
        $columnName = 'new_custom_column';

        $headers = array_keys($this->values);
        $headers[] = $columnName;
        $columnResolver = $this->createColumnResolver($headers);
        $values = array_values($this->values);
        $columnResolver->getColumnValue($columnName, $values);
    }
}
