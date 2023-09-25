<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Elasticsearch\Test\Unit\Model\Adapter\FieldMapper;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\FieldMapperResolver;
use Magento\Framework\ObjectManagerInterface;

class FieldMapperResolverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var FieldMapperResolver
     */
    private $model;

    /**
     * @var ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectManagerMock;

    /**
     * @var string[]
     */
    private $fieldMappers;

    /**
     * @var FieldMapperInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fieldMapperEntity;

    /**
     * Set up test environment
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->fieldMapperEntity = $this->getMockBuilder(
            \Magento\Elasticsearch\Model\Adapter\FieldMapperInterface::class
        )
            ->disableOriginalConstructor()
            ->getMock();
        $this->fieldMappers = [
            'product' => 'productFieldMapper',
        ];
        $objectManager = new ObjectManagerHelper($this);
        $this->model = $objectManager->getObject(
            \Magento\Elasticsearch\Model\Adapter\FieldMapper\FieldMapperResolver::class,
            [
                'objectManager' => $this->objectManagerMock,
                'fieldMappers' => $this->fieldMappers
            ]
        );
    }

    /**
     * Test getFieldName() with Exception
     * @return void
     */
    public function testGetFieldNameEmpty()
    {
        $this->expectException(\Exception::class);

        $this->model->getFieldName('attribute', ['entityType' => '']);
    }

    /**
     * Test getFieldName() with Exception
     * @return void
     */
    public function testGetFieldNameWrongType()
    {
        $this->expectException(\LogicException::class);

        $this->model->getFieldName('attribute', ['entityType' => 'error']);
    }

    /**
     * Test getFieldName() with Exception
     * @return void
     */
    public function testGetFieldNameFailure()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->willReturn(false);
        $this->model->getFieldName('attribute', ['entityType' => 'product']);
    }

    /**
     * Test getFieldName() method
     * @return void
     */
    public function testGetFieldName()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->willReturn($this->fieldMapperEntity);
        $this->model->getFieldName('attribute', []);
    }

    /**
     * Test getAllAttributesTypes() method
     * @return void
     */
    public function testGetAllAttributesTypes()
    {
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->willReturn($this->fieldMapperEntity);
        $this->model->getAllAttributesTypes([]);
    }
}
