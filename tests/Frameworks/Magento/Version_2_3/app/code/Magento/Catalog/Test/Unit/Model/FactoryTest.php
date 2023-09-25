<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model;

use \Magento\Catalog\Model\Factory;

class FactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \Magento\Catalog\Model\Product\Option
     */
    protected $model;

    /**
     * @var Factory
     */
    protected $factory;

    public function testCreate()
    {
        $this->assertInstanceOf(\Magento\Catalog\Model\Product\Option::class, $this->factory->create('model', []));
    }

    /**
     */
    public function testExceptionCreate()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->factory->create('null', []);
    }

    protected function setUp(): void
    {
        $this->model = $this->createMock(\Magento\Catalog\Model\Product\Option::class);

        $this->setObjectManager();

        $this->factory = new Factory($this->objectManager);
    }

    protected function setObjectManager()
    {
        $this->objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $this->objectManager
            ->expects($this->any())
            ->method('create')
            ->with($this->logicalOr($this->equalTo('model'), $this->equalTo('null')), $this->equalTo([]))
            ->willReturnCallback(function ($className) {
                $returnValue = null;
                if ($className == 'model') {
                    $returnValue = $this->model;
                }
                return $returnValue;
            });
    }
}
