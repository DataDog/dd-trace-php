<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Test\Unit\Controller\Page;

use Magento\Cms\Controller\Adminhtml\Page\PostDataProcessor;
use Magento\Framework\Stdlib\DateTime\Filter\Date;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\View\Model\Layout\Update\ValidatorFactory;

/**
 * Class PostDataProcessorTest
 * @package Magento\Cms\Test\Unit\Controller\Page
 */
class PostDataProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Date|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dateFilterMock;

    /**
     * @var ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManagerMock;

    /**
     * @var ValidatorFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $validatorFactoryMock;

    /**
     * @var PostDataProcessor
     */
    protected $postDataProcessor;

    protected function setUp(): void
    {
        $this->dateFilterMock = $this->getMockBuilder(Date::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->messageManagerMock = $this->getMockBuilder(ManagerInterface::class)
            ->getMockForAbstractClass();
        $this->validatorFactoryMock = $this->getMockBuilder(ValidatorFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $this->postDataProcessor = (new ObjectManager($this))->getObject(
            PostDataProcessor::class,
            [
                'dateFilter' => $this->dateFilterMock,
                'messageManager' => $this->messageManagerMock,
                'validatorFactory' => $this->validatorFactoryMock
            ]
        );
    }

    public function testValidateRequireEntry()
    {
        $postData = [
            'title' => ''
        ];
        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('To apply changes you should fill in hidden required "%1" field', 'Page Title'));

        $this->assertFalse($this->postDataProcessor->validateRequireEntry($postData));
    }

    public function testFilter()
    {
        $this->assertSame(['key' => 'value'], $this->postDataProcessor->filter(['key' => 'value']));
    }
}
