<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Security\Test\Unit\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Security\Model\AdminSessionInfo;
use Magento\Security\Model\ConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Test class for \Magento\Security\Model\AdminSessionInfo testing
 */
class AdminSessionInfoTest extends TestCase
{
    /**
     * @var  AdminSessionInfo
     */
    protected $model;

    /**
     * @var MockObject|ConfigInterface
     */
    protected $securityConfigMock;

    /**
     * @var DateTime
     */
    protected $dateTimeMock;

    /**
     * @var  ObjectManager
     */
    protected $objectManager;

    /**
     * Init mocks for tests
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->securityConfigMock =  $this->getMockBuilder(ConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->dateTimeMock =  $this->getMockBuilder(DateTime::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = $this->objectManager->getObject(
            AdminSessionInfo::class,
            [
                'securityConfig' => $this->securityConfigMock,
                'dateTime' => $this->dateTimeMock,
            ]
        );
    }

    /**
     * @return void
     */
    public function testIsLoggedInStatus()
    {
        $this->model->setData('status', AdminSessionInfo::LOGGED_IN);
        $this->model->setUpdatedAt(901);
        $this->securityConfigMock->expects($this->once())->method('getAdminSessionLifetime')->willReturn(100);
        $this->dateTimeMock->expects($this->once())
            ->method('gmtTimestamp')
            ->willReturn(1000);
        $this->assertTrue($this->model->isLoggedInStatus());
    }

    /**
     * @return void
     */
    public function testIsLoggedInStatusExpired()
    {
        $this->model->setData('status', AdminSessionInfo::LOGGED_IN);
        $this->model->setUpdatedAt(899);
        $this->securityConfigMock->expects($this->once())->method('getAdminSessionLifetime')->willReturn(100);
        $this->dateTimeMock->expects($this->once())
            ->method('gmtTimestamp')
            ->willReturn(1000);
        $this->assertFalse($this->model->isLoggedInStatus());
        $this->assertEquals(AdminSessionInfo::LOGGED_OUT, $this->model->getStatus());
    }

    /**
     * @param bool $expectedResult
     * @param string $sessionLifetime
     * @dataProvider dataProviderSessionLifetime
     */
    public function testSessionExpired($expectedResult, $sessionLifetime)
    {
        $timestamp = time();

        $this->securityConfigMock->expects($this->once())
            ->method('getAdminSessionLifetime')
            ->willReturn($sessionLifetime);

        $this->dateTimeMock->expects($this->once())
            ->method('gmtTimestamp')
            ->willReturn($timestamp);

        $this->model->setUpdatedAt(
            date("Y-m-d H:i:s", $timestamp - 1)
        );

        $this->assertEquals($expectedResult, $this->model->isSessionExpired());
    }

    /**
     * @return array
     */
    public function dataProviderSessionLifetime()
    {
        return [
            ['expectedResult' => true, 'sessionLifetime' => '0'],
            ['expectedResult' => true, 'sessionLifetime' => '1'],
            ['expectedResult' => false, 'sessionLifetime' => '2']
        ];
    }

    /**
     * @return void
     */
    public function testSessionExpiredWhenUpdatedAtIsNull()
    {
        $timestamp = time();
        $sessionLifetime = '1';

        $this->securityConfigMock->expects($this->once())
            ->method('getAdminSessionLifetime')
            ->willReturn($sessionLifetime);

        $this->dateTimeMock->expects($this->once())
            ->method('gmtTimestamp')
            ->willReturn($timestamp);

        $this->model->setUpdatedAt(null);
        $this->assertTrue($this->model->isSessionExpired());
    }
    
    /**
     * @return void
     */
    public function testGetFormattedIp()
    {
        $formattedIp = '127.0.0.1';
        $this->model->setIp($formattedIp);
        $this->assertEquals($formattedIp, $this->model->getFormattedIp());
    }

    /**
     * @return void
     */
    public function testIsOtherSessionsTerminated()
    {
        $this->assertFalse($this->model->isOtherSessionsTerminated());
    }

    /**
     * @param bool $isOtherSessionsTerminated
     * @dataProvider dataProviderIsOtherSessionsTerminated
     */
    public function testSetIsOtherSessionsTerminated($isOtherSessionsTerminated)
    {
        $this->assertInstanceOf(
            AdminSessionInfo::class,
            $this->model->setIsOtherSessionsTerminated($isOtherSessionsTerminated)
        );
    }

    /**
     * @return array
     */
    public function dataProviderIsOtherSessionsTerminated()
    {
        return [[true], [false]];
    }
}
