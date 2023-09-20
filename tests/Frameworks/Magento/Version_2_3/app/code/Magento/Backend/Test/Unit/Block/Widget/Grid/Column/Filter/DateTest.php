<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Backend\Test\Unit\Block\Widget\Grid\Column\Filter;

/**
 * Class DateTest to test Magento\Backend\Block\Widget\Grid\Column\Filter\Date
 *
 */
class DateTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Backend\Block\Widget\Grid\Column\Filter\Date */
    protected $model;

    /** @var \Magento\Framework\Math\Random|\PHPUnit\Framework\MockObject\MockObject */
    protected $mathRandomMock;

    /** @var \Magento\Framework\Locale\ResolverInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $localeResolverMock;

    /** @var \Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $dateTimeFormatterMock;

    /** @var \Magento\Backend\Block\Widget\Grid\Column|\PHPUnit\Framework\MockObject\MockObject */
    protected $columnMock;

    /** @var \Magento\Framework\Stdlib\DateTime\TimezoneInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $localeDateMock;

    /** @var \Magento\Framework\Escaper|\PHPUnit\Framework\MockObject\MockObject */
    private $escaperMock;

    /** @var \Magento\Backend\Block\Context|\PHPUnit\Framework\MockObject\MockObject */
    private $contextMock;

    protected function setUp(): void
    {
        $this->mathRandomMock = $this->getMockBuilder(\Magento\Framework\Math\Random::class)
            ->disableOriginalConstructor()
            ->setMethods(['getUniqueHash'])
            ->getMock();

        $this->localeResolverMock = $this->getMockBuilder(\Magento\Framework\Locale\ResolverInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $this->dateTimeFormatterMock = $this
            ->getMockBuilder(\Magento\Framework\Stdlib\DateTime\DateTimeFormatterInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $this->columnMock = $this->getMockBuilder(\Magento\Backend\Block\Widget\Grid\Column::class)
            ->disableOriginalConstructor()
            ->setMethods(['getTimezone', 'getHtmlId', 'getId'])
            ->getMock();

        $this->localeDateMock = $this->getMockBuilder(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $this->escaperMock = $this->getMockBuilder(\Magento\Framework\Escaper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextMock = $this->getMockBuilder(\Magento\Backend\Block\Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextMock->expects($this->once())->method('getEscaper')->willReturn($this->escaperMock);
        $this->contextMock->expects($this->once())->method('getLocaleDate')->willReturn($this->localeDateMock);

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManagerHelper->getObject(
            \Magento\Backend\Block\Widget\Grid\Column\Filter\Date::class,
            [
                'mathRandom' => $this->mathRandomMock,
                'localeResolver' => $this->localeResolverMock,
                'dateTimeFormatter' => $this->dateTimeFormatterMock,
                'localeDate' => $this->localeDateMock,
                'context' => $this->contextMock,
            ]
        );
        $this->model->setColumn($this->columnMock);
    }

    public function testGetHtmlSuccessfulTimestamp()
    {
        $uniqueHash = 'H@$H';
        $id = 3;
        $format = 'mm/dd/yyyy';
        $yesterday = new \DateTime();
        $yesterday->add(\DateInterval::createFromDateString('yesterday'));
        $tomorrow = new \DateTime();
        $tomorrow->add(\DateInterval::createFromDateString('tomorrow'));
        $value = [
            'locale' => 'en_US',
            'from' => $yesterday->getTimestamp(),
            'to' => $tomorrow->getTimestamp()
        ];

        $this->mathRandomMock->expects($this->any())->method('getUniqueHash')->willReturn($uniqueHash);
        $this->columnMock->expects($this->once())->method('getHtmlId')->willReturn($id);
        $this->localeDateMock->expects($this->any())->method('getDateFormat')->willReturn($format);
        $this->columnMock->expects($this->any())->method('getTimezone')->willReturn(false);
        $this->localeResolverMock->expects($this->any())->method('getLocale')->willReturn('en_US');
        $this->model->setColumn($this->columnMock);
        $this->model->setValue($value);

        $output = $this->model->getHtml();
        $this->assertStringContainsString(
            'id="' . $uniqueHash . '_from" value="' . $yesterday->getTimestamp(),
            $output
        );
        $this->assertStringContainsString('id="' . $uniqueHash . '_to" value="' . $tomorrow->getTimestamp(), $output);
    }

    public function testGetEscapedValueEscapeString()
    {
        $value = "\"><img src=x onerror=alert(2) />";
        $array = [
            'orig_from' => $value,
            'from' => $value,
        ];
        $this->model->setValue($array);
        $this->escaperMock->expects($this->once())->method('escapeHtml')->with($value);
        $this->model->getEscapedValue('from');
    }
}
