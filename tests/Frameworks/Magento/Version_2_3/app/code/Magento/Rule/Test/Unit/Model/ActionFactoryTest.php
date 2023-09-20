<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Rule\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class ActionFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Rule\Model\ActionFactory
     */
    protected $actionFactory;

    /**
     * @var ObjectManagerHelper
     */
    protected $objectManagerHelper;

    /**
     * @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    protected function setUp(): void
    {
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);

        $this->objectManagerHelper = new ObjectManagerHelper($this);
        $this->actionFactory = $this->objectManagerHelper->getObject(
            \Magento\Rule\Model\ActionFactory::class,
            [
                'objectManager' => $this->objectManagerMock
            ]
        );
    }

    public function testCreate()
    {
        $type = '1';
        $data = ['data2', 'data3'];
        $this->objectManagerMock->expects($this->once())->method('create')->with($type, $data);
        $this->actionFactory->create($type, $data);
    }
}
