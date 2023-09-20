<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\File\FileList;

class FactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\File\FileList\Factory
     */
    protected $model;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $objectManager;

    protected function setUp()
    {
        $this->objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->model = new \Magento\Framework\View\File\FileList\Factory($this->objectManager);
    }

    public function testCreate()
    {
        $helperObjectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $collator = $helperObjectManager->getObject(\Magento\Framework\View\File\FileList\Factory::FILE_LIST_COLLATOR);
        $list = $helperObjectManager->getObject(\Magento\Framework\View\File\FileList::class);

        $this->objectManager
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo(\Magento\Framework\View\File\FileList\Factory::FILE_LIST_COLLATOR))
            ->will($this->returnValue($collator));

        $this->objectManager
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->equalTo(\Magento\Framework\View\File\FileList::class),
                $this->equalTo(['collator' => $collator])
            )
            ->will($this->returnValue($list));
        $this->assertSame($list, $this->model->create());
    }

    /**
     * @expectedException \UnexpectedValueException
     * @expectedExceptionMessage Magento\Framework\View\File\FileList\Collator has to implement the collate interface.
     */
    public function testCreateException()
    {
        $collator = new \stdClass();

        $this->objectManager
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo(\Magento\Framework\View\File\FileList\Factory::FILE_LIST_COLLATOR))
            ->will($this->returnValue($collator));

        $this->model->create();
    }
}
