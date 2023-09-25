<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
// @codingStandardsIgnoreStart
namespace {
    $mockPHPFunctions = false;
}

namespace Magento\Framework\Session\Test\Unit {
    // @codingStandardsIgnoreEnd

    /**
     * Test SessionManager
     *
     */
    class SessionManagerTest extends \PHPUnit\Framework\TestCase
    {
        const SESSION_USE_ONLY_COOKIES = 'session.use_only_cookies';
        const SESSION_USE_ONLY_COOKIES_ENABLE = '1';

        /**
         * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
         */
        private $objectManager;

        /**
         * @var \Magento\Framework\Session\SessionManager
         */
        private $sessionManager;

        /**
         * @var \Magento\Framework\Session\Config\ConfigInterface | \PHPUnit\Framework\MockObject\MockObject
         */
        private $mockSessionConfig;

        /**
         * @var \Magento\Framework\Stdlib\CookieManagerInterface | \PHPUnit\Framework\MockObject\MockObject
         */
        private $mockCookieManager;

        /**
         * @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory | \PHPUnit\Framework\MockObject\MockObject
         */
        private $mockCookieMetadataFactory;

        /**
         * @var bool
         */
        public static $isIniSetInvoked;

        protected function setUp(): void
        {
            $this->markTestSkipped('To be fixed in MAGETWO-34751');
            global $mockPHPFunctions;
            require_once __DIR__ . '/_files/mock_ini_set.php';
            require_once __DIR__ . '/_files/mock_session_regenerate_id.php';

            $mockPHPFunctions = true;
            $this->mockSessionConfig = $this->getMockBuilder(\Magento\Framework\Session\Config\ConfigInterface::class)
                ->disableOriginalConstructor()
                ->getMock();
            $this->mockCookieManager = $this->createMock(\Magento\Framework\Stdlib\CookieManagerInterface::class);
            $this->mockCookieMetadataFactory = $this->getMockBuilder(
                \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory::class
            )
                ->disableOriginalConstructor()
                ->getMock();
            $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
            $arguments = [
                'sessionConfig' => $this->mockSessionConfig,
                'cookieManager' => $this->mockCookieManager,
                'cookieMetadataFactory' => $this->mockCookieMetadataFactory,
            ];
            $this->sessionManager = $this->objectManager->getObject(
                \Magento\Framework\Session\SessionManager::class,
                $arguments
            );
        }

        public function testSessionManagerConstructor()
        {
            self::$isIniSetInvoked = false;
            $this->objectManager->getObject(\Magento\Framework\Session\SessionManager::class);
            $this->assertTrue(SessionManagerTest::$isIniSetInvoked);
        }
    }
}
