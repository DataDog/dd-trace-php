<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Elasticsearch\Test\Unit\Model\Adapter\FieldMapper\Product\FieldProvider\FieldName\Resolver;

use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeAdapter;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProvider\FieldName\Resolver\NotEavAttribute;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD)
 */
class NotEavAttributeTest extends TestCase
{
    /**
     * @var NotEavAttribute
     */
    private $resolver;

    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManagerHelper($this);

        $this->resolver = $objectManager->getObject(
            NotEavAttribute::class
        );
    }

    /**
     * @dataProvider getFieldNameProvider
     * @param $attributeCode
     * @param $isEavAttribute
     * @param $context
     * @param $expected
     * @return void
     */
    public function testGetFieldName($attributeCode, $isEavAttribute, $context, $expected)
    {
        $attributeMock = $this->getMockBuilder(AttributeAdapter::class)
            ->disableOriginalConstructor()
            ->setMethods(['isEavAttribute', 'getAttributeCode'])
            ->getMock();
        $attributeMock->expects($this->any())
            ->method('isEavAttribute')
            ->willReturn($isEavAttribute);
        $attributeMock->expects($this->any())
            ->method('getAttributeCode')
            ->willReturn($attributeCode);

        $this->assertEquals(
            $expected,
            $this->resolver->getFieldName($attributeMock, $context)
        );
    }

    /**
     * @return array
     */
    public function getFieldNameProvider()
    {
        return [
            ['code', true, [], ''],
            ['code', false, [], 'code'],
        ];
    }
}
