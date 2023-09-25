<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Product\CopyConstructor;

class UpSellTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Model\Product\CopyConstructor\UpSell
     */
    protected $_model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_productMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_duplicateMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_linkMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $_linkCollectionMock;

    protected function setUp(): void
    {
        $this->_model = new \Magento\Catalog\Model\Product\CopyConstructor\UpSell();

        $this->_productMock = $this->createMock(\Magento\Catalog\Model\Product::class);

        $this->_duplicateMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['setUpSellLinkData', '__wakeup']
        );

        $this->_linkMock = $this->createPartialMock(
            \Magento\Catalog\Model\Product\Link::class,
            ['__wakeup', 'getAttributes', 'getUpSellLinkCollection', 'useUpSellLinks']
        );

        $this->_productMock->expects(
            $this->any()
        )->method(
            'getLinkInstance'
        )->willReturn(
            $this->_linkMock
        );
    }

    public function testBuild()
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $expectedData = ['100500' => ['some' => 'data']];

        $attributes = ['attributeOne' => ['code' => 'one'], 'attributeTwo' => ['code' => 'two']];

        $this->_linkMock->expects($this->once())->method('useUpSellLinks');

        $this->_linkMock->expects($this->once())->method('getAttributes')->willReturn($attributes);

        $productLinkMock = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\Link::class,
            ['__wakeup', 'getLinkedProductId', 'toArray']
        );

        $productLinkMock->expects($this->once())->method('getLinkedProductId')->willReturn('100500');
        $productLinkMock->expects(
            $this->once()
        )->method(
            'toArray'
        )->with(
            ['one', 'two']
        )->willReturn(
            ['some' => 'data']
        );

        $collectionMock = $helper->getCollectionMock(
            \Magento\Catalog\Model\ResourceModel\Product\Link\Collection::class,
            [$productLinkMock]
        );
        $this->_productMock->expects(
            $this->once()
        )->method(
            'getUpSellLinkCollection'
        )->willReturn(
            $collectionMock
        );

        $this->_duplicateMock->expects($this->once())->method('setUpSellLinkData')->with($expectedData);

        $this->_model->build($this->_productMock, $this->_duplicateMock);
    }
}
