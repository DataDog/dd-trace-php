<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Analytics\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Analytics\Block\Adminhtml\System\Config\CollectionTimeLabel;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class CollectionTimeLabelTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CollectionTimeLabel
     */
    private $collectionTimeLabel;

    /**
     * @var Context|\PHPUnit\Framework\MockObject\MockObject
     */
    private $contextMock;

    /**
     * @var TimezoneInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $timeZoneMock;

    /**
     * @var AbstractElement|\PHPUnit\Framework\MockObject\MockObject
     */
    private $abstractElementMock;

    /**
     * @var ResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $localeResolver;

    /**
     * @var Form|\PHPUnit\Framework\MockObject\MockObject
     */
    private $formMock;

    protected function setUp(): void
    {
        $this->abstractElementMock = $this->getMockBuilder(AbstractElement::class)
            ->setMethods(['getComment'])
            ->disableOriginalConstructor()
            ->getMock();

        $objectManager = new ObjectManager($this);
        $escaper = $objectManager->getObject(\Magento\Framework\Escaper::class);
        $reflection = new \ReflectionClass($this->abstractElementMock);
        $reflection_property = $reflection->getProperty('_escaper');
        $reflection_property->setAccessible(true);
        $reflection_property->setValue($this->abstractElementMock, $escaper);

        $this->contextMock = $this->getMockBuilder(Context::class)
            ->setMethods(['getLocaleDate'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->formMock = $this->getMockBuilder(Form::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->timeZoneMock = $this->getMockBuilder(TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->contextMock->expects($this->any())
            ->method('getLocaleDate')
            ->willReturn($this->timeZoneMock);
        $this->localeResolver = $this->getMockBuilder(ResolverInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getLocale'])
            ->getMockForAbstractClass();

        $objectManager = new ObjectManager($this);
        $this->collectionTimeLabel = $objectManager->getObject(
            CollectionTimeLabel::class,
            [
                'context' => $this->contextMock,
                'localeResolver' => $this->localeResolver
            ]
        );
    }

    public function testRender()
    {
        $timeZone = "America/New_York";
        $this->abstractElementMock->setForm($this->formMock);
        $this->timeZoneMock->expects($this->once())
            ->method('getConfigTimezone')
            ->willReturn($timeZone);
        $this->abstractElementMock->expects($this->any())
            ->method('getComment')
            ->willReturn('Eastern Standard Time (America/New_York)');
        $this->localeResolver->expects($this->once())
            ->method('getLocale')
            ->willReturn('en_US');
        $this->assertMatchesRegularExpression(
            "/Eastern Standard Time \(America\/New_York\)/",
            $this->collectionTimeLabel->render($this->abstractElementMock)
        );
    }
}
