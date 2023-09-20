<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Test class for \Magento\TestFramework\Bootstrap\DocBlock.
 */
namespace Magento\Test\Bootstrap;

class DocBlockTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\TestFramework\Bootstrap\DocBlock
     */
    protected $_object;

    /**
     * @var \Magento\TestFramework\Application|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_application;

    protected function setUp(): void
    {
        $this->_object = new \Magento\TestFramework\Bootstrap\DocBlock(__DIR__);
        $this->_application = $this->createMock(\Magento\TestFramework\Application::class);
    }

    protected function tearDown(): void
    {
        $this->_object = null;
        $this->_application = null;
    }

    /**
     * Setup expectation of inability to instantiate an event listener without passing the event manager instance
     *
     * @param string $listenerClass
     * @param string $expectedExceptionMsg
     */
    protected function _expectNoListenerCreation($listenerClass, $expectedExceptionMsg)
    {
        try {
            new $listenerClass();
            $this->fail("Inability to instantiate the event listener '{$listenerClass}' is expected.");
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->assertEquals($expectedExceptionMsg, $e->getMessage());
        }
    }

    public function testRegisterAnnotations()
    {
        $this->_expectNoListenerCreation(
            \Magento\TestFramework\Event\PhpUnit::class,
            'Instance of the event manager is required.'
        );
        $this->_expectNoListenerCreation(
            \Magento\TestFramework\Event\Magento::class,
            'Instance of the "Magento\TestFramework\EventManager" is expected.'
        );
        $this->_object->registerAnnotations($this->_application);
        new \Magento\TestFramework\Event\PhpUnit();
        new \Magento\TestFramework\Event\Magento();
    }
}
