<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Ui\Test\Unit\Component;

use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Ui\Component\Form;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FormTest extends TestCase
{
    /** @var Form */
    protected $model;

    /** @var ContextInterface|MockObject */
    protected $contextMock;

    /** @var FilterBuilder|MockObject */
    protected $filterBuilderMock;

    protected function setUp(): void
    {
        $this->contextMock = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $this->filterBuilderMock = $this->getMockBuilder(FilterBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextMock->expects($this->never())->method('getProcessor');

        $this->model = new Form(
            $this->contextMock,
            $this->filterBuilderMock
        );
    }

    public function testGetComponentName()
    {
        $this->assertEquals(Form::NAME, $this->model->getComponentName());
    }

    public function testGetDataSourceData()
    {
        $requestFieldName = 'request_id';
        $primaryFieldName = 'primary_id';
        $fieldId = 44;
        $row = ['key' => 'value'];
        $data = [
            $fieldId => $row,
        ];
        $dataSource = [
            'data' => $row,
        ];

        /** @var DataProviderInterface|MockObject $dataProviderMock */
        $dataProviderMock =
            $this->getMockBuilder(DataProviderInterface::class)
                ->getMock();
        $dataProviderMock->expects($this->once())
            ->method('getRequestFieldName')
            ->willReturn($requestFieldName);
        $dataProviderMock->expects($this->once())
            ->method('getPrimaryFieldName')
            ->willReturn($primaryFieldName);

        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProviderMock);
        $this->contextMock->expects($this->once())
            ->method('getRequestParam')
            ->with($requestFieldName)
            ->willReturn($fieldId);

        /** @var Filter|MockObject $filterMock */
        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filterBuilderMock->expects($this->once())
            ->method('setField')
            ->with($primaryFieldName)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setValue')
            ->with($fieldId)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);

        $dataProviderMock->expects($this->once())
            ->method('addFilter')
            ->with($filterMock);
        $dataProviderMock->expects($this->once())
            ->method('getData')
            ->willReturn($data);

        $this->assertEquals($dataSource, $this->model->getDataSourceData());
    }

    public function testGetDataSourceDataWithoutData()
    {
        $requestFieldName = 'request_id';
        $primaryFieldName = 'primary_id';
        $fieldId = 44;
        $data = [];
        $dataSource = [];

        /** @var DataProviderInterface|MockObject $dataProviderMock */
        $dataProviderMock =
            $this->getMockBuilder(DataProviderInterface::class)
                ->getMock();
        $dataProviderMock->expects($this->once())
            ->method('getRequestFieldName')
            ->willReturn($requestFieldName);
        $dataProviderMock->expects($this->once())
            ->method('getPrimaryFieldName')
            ->willReturn($primaryFieldName);

        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProviderMock);
        $this->contextMock->expects($this->once())
            ->method('getRequestParam')
            ->with($requestFieldName)
            ->willReturn($fieldId);

        /** @var Filter|MockObject $filterMock */
        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filterBuilderMock->expects($this->once())
            ->method('setField')
            ->with($primaryFieldName)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setValue')
            ->with($fieldId)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);

        $dataProviderMock->expects($this->once())
            ->method('addFilter')
            ->with($filterMock);
        $dataProviderMock->expects($this->once())
            ->method('getData')
            ->willReturn($data);

        $this->assertEquals($dataSource, $this->model->getDataSourceData());
    }

    public function testGetDataSourceDataWithoutId()
    {
        $requestFieldName = 'request_id';
        $primaryFieldName = 'primary_id';
        $fieldId = null;
        $row = ['key' => 'value'];
        $data = [
            $fieldId => $row,
        ];
        $dataSource = [
            'data' => $row,
        ];

        /** @var DataProviderInterface|MockObject $dataProviderMock */
        $dataProviderMock =
            $this->getMockBuilder(DataProviderInterface::class)
                ->getMock();
        $dataProviderMock->expects($this->once())
            ->method('getRequestFieldName')
            ->willReturn($requestFieldName);
        $dataProviderMock->expects($this->once())
            ->method('getPrimaryFieldName')
            ->willReturn($primaryFieldName);

        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProviderMock);
        $this->contextMock->expects($this->once())
            ->method('getRequestParam')
            ->with($requestFieldName)
            ->willReturn($fieldId);

        /** @var Filter|MockObject $filterMock */
        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filterBuilderMock->expects($this->once())
            ->method('setField')
            ->with($primaryFieldName)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setValue')
            ->with($fieldId)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);

        $dataProviderMock->expects($this->once())
            ->method('addFilter')
            ->with($filterMock);
        $dataProviderMock->expects($this->once())
            ->method('getData')
            ->willReturn($data);

        $this->assertEquals($dataSource, $this->model->getDataSourceData());
    }

    public function testGetDataSourceDataWithAbstractDataProvider()
    {
        $requestFieldName = 'request_id';
        $primaryFieldName = 'primary_id';
        $fieldId = 44;
        $row = ['key' => 'value', $primaryFieldName => $fieldId];
        $data = [
            'items' => [$row],
        ];
        $dataSource = [
            'data' => [
                'general' => $row
            ],
        ];

        /** @var DataProviderInterface|MockObject $dataProviderMock */
        $dataProviderMock =
            $this->getMockBuilder(DataProviderInterface::class)
                ->getMock();
        $dataProviderMock->expects($this->once())
            ->method('getRequestFieldName')
            ->willReturn($requestFieldName);
        $dataProviderMock->expects($this->once())
            ->method('getPrimaryFieldName')
            ->willReturn($primaryFieldName);

        $this->contextMock->expects($this->any())
            ->method('getDataProvider')
            ->willReturn($dataProviderMock);
        $this->contextMock->expects($this->once())
            ->method('getRequestParam')
            ->with($requestFieldName)
            ->willReturn($fieldId);

        /** @var Filter|MockObject $filterMock */
        $filterMock = $this->getMockBuilder(Filter::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->filterBuilderMock->expects($this->once())
            ->method('setField')
            ->with($primaryFieldName)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setValue')
            ->with($fieldId)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);

        $dataProviderMock->expects($this->once())
            ->method('addFilter')
            ->with($filterMock);
        $dataProviderMock->expects($this->once())
            ->method('getData')
            ->willReturn($data);

        $this->assertEquals($dataSource, $this->model->getDataSourceData());
    }
}
