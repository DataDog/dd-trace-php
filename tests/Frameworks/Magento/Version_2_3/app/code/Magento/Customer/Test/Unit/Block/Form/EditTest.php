<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Block\Form;

use Magento\Customer\Model\AccountManagement;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Test class for \Magento\Customer\Block\Form\Edit
 */
class EditTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfigMock;

    /**
     * @var \Magento\Customer\Block\Form\Edit
     */
    protected $block;

    /**
     * Init mocks for tests
     * @return void
     */
    protected function setUp(): void
    {
        $this->scopeConfigMock =  $this->getMockBuilder(ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        /** @var \Magento\Framework\View\Element\Template\Context | \PHPUnit\Framework\MockObject\MockObject $context */
        $context = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);
        $context->expects($this->any())
            ->method('getScopeConfig')
            ->willReturn($this->scopeConfigMock);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->block = $objectManager->getObject(
            \Magento\Customer\Block\Form\Edit::class,
            ['context' => $context]
        );
    }

    /**
     * @return void
     */
    public function testGetMinimumPasswordLength()
    {
        $minimumPasswordLength = '8';

        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(AccountManagement::XML_PATH_MINIMUM_PASSWORD_LENGTH)
            ->willReturn($minimumPasswordLength);

        $this->assertEquals($minimumPasswordLength, $this->block->getMinimumPasswordLength());
    }

    /**
     * @return void
     */
    public function testGetRequiredCharacterClassesNumber()
    {
        $requiredCharacterClassesNumber = '4';

        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(AccountManagement::XML_PATH_REQUIRED_CHARACTER_CLASSES_NUMBER)
            ->willReturn($requiredCharacterClassesNumber);

        $this->assertEquals($requiredCharacterClassesNumber, $this->block->getRequiredCharacterClassesNumber());
    }
}
