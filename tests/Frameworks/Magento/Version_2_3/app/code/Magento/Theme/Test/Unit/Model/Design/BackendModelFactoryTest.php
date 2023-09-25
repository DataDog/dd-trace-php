<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Design;

class BackendModelFactoryTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Theme\Model\Design\BackendModelFactory */
    protected $model;

    /** @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $objectManagerMock;

    /** @var \Magento\Theme\Model\Design\Config\MetadataProvider|\PHPUnit\Framework\MockObject\MockObject */
    protected $metadataProviderMock;

    /**
     * @var \Magento\Theme\Model\ResourceModel\Design\Config\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $collectionFactoryMock;

    /** @var \Magento\Theme\Model\ResourceModel\Design\Config\Collection|\PHPUnit\Framework\MockObject\MockObject */
    protected $collection;

    /** @var \Magento\Framework\App\Config\Value|\PHPUnit\Framework\MockObject\MockObject */
    protected $backendModel;

    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(\Magento\Framework\ObjectManagerInterface::class)
            ->getMockForAbstractClass();
        $this->metadataProviderMock = $this->getMockBuilder(\Magento\Theme\Model\Design\Config\MetadataProvider::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->collectionFactoryMock = $this->getMockBuilder(
            \Magento\Theme\Model\ResourceModel\Design\Config\CollectionFactory::class
        )
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->collection = $this->getMockBuilder(\Magento\Theme\Model\ResourceModel\Design\Config\Collection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->backendModel = $this->getMockBuilder(\Magento\Framework\App\Config\Value::class)
            ->disableOriginalConstructor()
            ->setMethods(['setValue'])
            ->getMock();
        
        $this->model = new \Magento\Theme\Model\Design\BackendModelFactory(
            $this->objectManagerMock,
            $this->metadataProviderMock,
            $this->collectionFactoryMock
        );
    }

    public function testCreate()
    {
        $scope = 'website';
        $scopeId = 1;
        $data = [
            'scope' => $scope,
            'scopeId' => $scopeId,
            'value' => 'value',
            'config' => [
                'path' => 'design/head/default_title',
                'backend_model' => \Magento\Framework\App\Config\Value::class
            ]
        ];
        $this->metadataProviderMock->expects($this->once())
            ->method('get')
            ->willReturn([
                'head_default_title' => [
                    'path' => 'design/head/default_title'
                ]
            ]);
        $this->collectionFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->collection);
        $this->collection->expects($this->once())
            ->method('addPathsFilter')
            ->with(['head_default_title' => 'design/head/default_title']);
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('scope', $scope);
        $this->collection->expects($this->once())
            ->method('addScopeIdFilter')
            ->with($scopeId);
        $this->collection->expects($this->once())
            ->method('getData')
            ->willReturn([
                [
                    'config_id' => 1,
                    'path' => 'design/head/default_title'
                ]
            ]);
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with(
                \Magento\Framework\App\Config\Value::class,
                [
                    'data' => [
                        'path' => 'design/head/default_title',
                        'scope' => $scope,
                        'scope_id' => $scopeId,
                        'field_config' => $data['config'],
                        'config_id' => 1
                    ]
                ]
            )
            ->willReturn($this->backendModel);
        $this->backendModel->expects($this->once())
            ->method('setValue')
            ->willReturn('value');
        $this->assertSame($this->backendModel, $this->model->create($data));
    }

    public function testCreateByPath()
    {
        $path = 'design/head/default_title';
        $backendModelType = \Magento\Theme\Model\Design\Backend\Exceptions::class;
        $backendModel = $this->getMockBuilder($backendModelType)
            ->disableOriginalConstructor()
            ->getMock();

        $this->metadataProviderMock->expects($this->once())
            ->method('get')
            ->willReturn([
                'head_default_title' => [
                    'path' => $path,
                    'backend_model' => $backendModelType
                ]
            ]);
        $this->objectManagerMock->expects($this->once())
            ->method('create')
            ->with($backendModelType, ['data' => []])
            ->willReturn($backendModel);
        $this->assertEquals($backendModel, $this->model->createByPath($path));
    }
}
