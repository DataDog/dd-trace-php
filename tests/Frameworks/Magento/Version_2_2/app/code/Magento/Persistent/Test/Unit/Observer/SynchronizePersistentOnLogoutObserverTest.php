<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Persistent\Test\Unit\Observer;

class SynchronizePersistentOnLogoutObserverTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Persistent\Observer\SynchronizePersistentOnLogoutObserver
     */
    protected $model;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $helperMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $sessionHelperMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $sessionFactoryMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $observerMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $sessionMock;

    protected function setUp()
    {
        $this->helperMock = $this->createMock(\Magento\Persistent\Helper\Data::class);
        $this->sessionHelperMock = $this->createMock(\Magento\Persistent\Helper\Session::class);
        $this->sessionFactoryMock =
            $this->createPartialMock(\Magento\Persistent\Model\SessionFactory::class, ['create']);
        $this->observerMock = $this->createMock(\Magento\Framework\Event\Observer::class);
        $this->sessionMock = $this->createMock(\Magento\Persistent\Model\Session::class);
        $this->model = new \Magento\Persistent\Observer\SynchronizePersistentOnLogoutObserver(
            $this->helperMock,
            $this->sessionHelperMock,
            $this->sessionFactoryMock
        );
    }

    public function testSynchronizePersistentOnLogoutWhenPersistentDataNotEnabled()
    {
        $this->helperMock->expects($this->once())->method('isEnabled')->will($this->returnValue(false));
        $this->sessionFactoryMock->expects($this->never())->method('create');
        $this->model->execute($this->observerMock);
    }

    public function testSynchronizePersistentOnLogoutWhenPersistentDataIsEnabled()
    {
        $this->helperMock->expects($this->once())->method('isEnabled')->will($this->returnValue(true));
        $this->helperMock->expects($this->once())->method('getClearOnLogout')->will($this->returnValue(true));
        $this->sessionFactoryMock
            ->expects($this->once())
            ->method('create')
            ->will($this->returnValue($this->sessionMock));
        $this->sessionMock->expects($this->once())->method('removePersistentCookie');
        $this->sessionHelperMock->expects($this->once())->method('setSession')->with(null);
        $this->model->execute($this->observerMock);
    }
}
