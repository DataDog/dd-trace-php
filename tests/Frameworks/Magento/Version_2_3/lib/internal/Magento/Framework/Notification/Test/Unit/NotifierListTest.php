<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Notification\Test\Unit;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class NotifierListTest extends \PHPUnit\Framework\TestCase
{
    /** @var ObjectManagerHelper */
    protected $objectManagerHelper;

    /** @var \Magento\Framework\ObjectManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->objectManagerHelper = new ObjectManagerHelper($this);
    }

    public function testAsArraySuccess()
    {
        $notifier1 = $this->objectManagerHelper->getObject(\Magento\Framework\Notification\NotifierPool::class);
        $notifier2 = $this->objectManagerHelper->getObject(\Magento\Framework\Notification\NotifierPool::class);
        $notifierList = $this->objectManagerHelper->getObject(
            \Magento\Framework\Notification\NotifierList::class,
            [
                'objectManager' => $this->objectManager,
                'notifiers' => [$notifier1, $notifier2]
            ]
        );
        $this->expectException('InvalidArgumentException');
        $result = $notifierList->asArray();
        foreach ($result as $notifier) {
            $this->assertInstanceOf(\Magento\Framework\Notification\NotifierInterface::class, $notifier);
        }
    }

    public function testAsArrayException()
    {
        $notifierCorrect = $this->objectManagerHelper->getObject(\Magento\Framework\Notification\NotifierPool::class);
        $notifierIncorrect = $this->objectManagerHelper->getObject(\Magento\Framework\Notification\NotifierList::class);
        $notifierList = $this->objectManagerHelper->getObject(
            \Magento\Framework\Notification\NotifierList::class,
            [
                'objectManager' => $this->objectManager,
                'notifiers' => [$notifierCorrect, $notifierIncorrect]
            ]
        );
        $this->expectException('InvalidArgumentException');
        $notifierList->asArray();
    }
}
