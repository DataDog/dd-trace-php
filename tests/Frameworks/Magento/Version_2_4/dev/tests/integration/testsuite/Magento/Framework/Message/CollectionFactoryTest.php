<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Message;

/**
 * \Magento\Framework\Message\CollectionFactory test case
 */
class CollectionFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Message\CollectionFactory
     */
    protected $model;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->model = $this->objectManager->create(\Magento\Framework\Message\CollectionFactory::class);
    }

    public function testCreate()
    {
        $message = $this->model->create();
        $this->assertInstanceOf(\Magento\Framework\Message\Collection::class, $message);
    }
}
