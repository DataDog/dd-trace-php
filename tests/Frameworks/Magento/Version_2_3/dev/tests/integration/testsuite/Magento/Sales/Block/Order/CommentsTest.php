<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Block\Order;

class CommentsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Block\Order\Comments
     */
    protected $_block;

    protected function setUp(): void
    {
        $this->_block = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
            \Magento\Framework\View\LayoutInterface::class
        )->createBlock(
            \Magento\Sales\Block\Order\Comments::class
        );
    }

    /**
     * @param string $commentedEntity
     * @param string $expectedClass
     * @dataProvider getCommentsDataProvider
     */
    public function testGetComments($commentedEntity, $expectedClass)
    {
        $commentedEntity = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create($commentedEntity);
        $this->_block->setEntity($commentedEntity);
        $comments = $this->_block->getComments();
        $this->assertInstanceOf($expectedClass, $comments);
    }

    /**
     * @return array
     */
    public function getCommentsDataProvider()
    {
        return [
            [
                \Magento\Sales\Model\Order\Invoice::class,
                \Magento\Sales\Model\ResourceModel\Order\Invoice\Comment\Collection::class,
            ],
            [
                \Magento\Sales\Model\Order\Creditmemo::class,
                \Magento\Sales\Model\ResourceModel\Order\Creditmemo\Comment\Collection::class
            ],
            [
                \Magento\Sales\Model\Order\Shipment::class,
                \Magento\Sales\Model\ResourceModel\Order\Shipment\Comment\Collection::class
            ]
        ];
    }

    public function testGetCommentsWrongEntityException()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $entity = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
            \Magento\Catalog\Model\Product::class
        );
        $this->_block->setEntity($entity);
        $this->_block->getComments();
    }
}
