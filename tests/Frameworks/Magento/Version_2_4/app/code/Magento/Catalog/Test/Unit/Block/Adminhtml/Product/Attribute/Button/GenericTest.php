<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Block\Adminhtml\Product\Attribute\Button;

use Magento\Catalog\Block\Adminhtml\Product\Edit\Button\Generic;
use Magento\Framework\Registry;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Element\UiComponent\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GenericTest extends TestCase
{
    /**
     * @var Context|MockObject
     */
    protected $contextMock;

    /**
     * @var Registry|MockObject
     */
    protected $registryMock;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->registryMock = $this->getMockBuilder(Registry::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return Generic
     */
    protected function getModel()
    {
        return $this->objectManager->getObject(Generic::class, [
            'context' => $this->contextMock,
            'registry' => $this->registryMock,
        ]);
    }

    public function testGetUrl()
    {
        $this->contextMock->expects($this->once())
            ->method('getUrl')
            ->willReturn('http://example.com');

        $this->assertSame('http://example.com', $this->getModel()->getUrl());
    }

    public function testGetButtonData()
    {
        $this->assertSame([], $this->getModel()->getButtonData());
    }
}
