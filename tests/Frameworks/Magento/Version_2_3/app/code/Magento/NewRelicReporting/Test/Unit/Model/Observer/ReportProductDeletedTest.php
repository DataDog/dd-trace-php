<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\NewRelicReporting\Test\Unit\Model\Observer;

use Magento\NewRelicReporting\Model\Observer\ReportProductDeleted;

/**
 * Class ReportProductDeletedTest
 */
class ReportProductDeletedTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ReportProductDeleted
     */
    protected $model;

    /**
     * @var \Magento\NewRelicReporting\Model\Config|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\NewRelicReporting\Model\SystemFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $systemFactory;

    /**
     * @var \Magento\NewRelicReporting\Model\System|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $systemModel;

    /**
     * @var \Magento\Framework\Json\EncoderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $jsonEncoder;

    /**
     * Setup
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->getMockBuilder(\Magento\NewRelicReporting\Model\Config::class)
            ->disableOriginalConstructor()
            ->setMethods(['isNewRelicEnabled'])
            ->getMock();
        $this->systemFactory = $this->getMockBuilder(\Magento\NewRelicReporting\Model\SystemFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->systemModel = $this->getMockBuilder(\Magento\NewRelicReporting\Model\System::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->jsonEncoder = $this->getMockBuilder(\Magento\Framework\Json\EncoderInterface::class)
            ->getMock();
        $this->systemFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->systemModel);

        $this->model = new ReportProductDeleted(
            $this->config,
            $this->systemFactory,
            $this->jsonEncoder
        );
    }

    /**
     * Test case when module is disabled in config
     *
     * @return void
     */
    public function testReportProductDeletedModuleDisabledFromConfig()
    {
        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(false);

        $this->model->execute($eventObserver);
    }

    /**
     * Test case when module is enabled in config
     *
     * @return void
     */
    public function testReportProductDeleted()
    {
        $testType = 'adminProductChange';
        $testAction = 'JSON string';

        /** @var \Magento\Framework\Event\Observer|\PHPUnit\Framework\MockObject\MockObject $eventObserver */
        $eventObserver = $this->getMockBuilder(\Magento\Framework\Event\Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->config->expects($this->once())
            ->method('isNewRelicEnabled')
            ->willReturn(true);
        $event = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->setMethods(['getProduct'])
            ->disableOriginalConstructor()
            ->getMock();
        $eventObserver->expects($this->once())
            ->method('getEvent')
            ->willReturn($event);
        $product = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->setMethods(['getId'])
            ->disableOriginalConstructor()
            ->getMock();
        $event->expects($this->once())
            ->method('getProduct')
            ->willReturn($product);
        $this->jsonEncoder->expects($this->once())
            ->method('encode')
            ->willReturn($testAction);
        $this->systemModel->expects($this->once())
            ->method('setData')
            ->with(['type' => $testType, 'action' => $testAction])
            ->willReturnSelf();
        $this->systemModel->expects($this->once())
            ->method('save');

        $this->model->execute($eventObserver);
    }
}
