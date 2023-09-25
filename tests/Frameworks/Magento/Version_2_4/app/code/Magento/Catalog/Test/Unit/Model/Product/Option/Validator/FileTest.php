<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Catalog\Test\Unit\Model\Product\Option\Validator;

use Magento\Catalog\Model\Config\Source\Product\Options\Price;
use Magento\Catalog\Model\Product\Option;
use Magento\Catalog\Model\Product\Option\Validator\File;
use Magento\Catalog\Model\ProductOptions\ConfigInterface;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    /**
     * @var File
     */
    protected $validator;

    /**
     * @var MockObject
     */
    protected $valueMock;

    /**
     * @var MockObject
     */
    protected $localeFormatMock;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $configMock = $this->getMockForAbstractClass(ConfigInterface::class);
        $storeManagerMock = $this->getMockForAbstractClass(StoreManagerInterface::class);
        $priceConfigMock = new Price($storeManagerMock);
        $this->localeFormatMock = $this->getMockForAbstractClass(FormatInterface::class);

        $config = [
            [
                'label' => 'group label 1',
                'types' => [
                    [
                        'label' => 'label 1.1',
                        'name' => 'name 1.1',
                        'disabled' => false
                    ]
                ]
            ],
            [
                'label' => 'group label 2',
                'types' => [
                    [
                        'label' => 'label 2.2',
                        'name' => 'name 2.2',
                        'disabled' => true
                    ]
                ]
            ]
        ];
        $configMock->expects($this->once())->method('getAll')->willReturn($config);
        $methods = ['getTitle', 'getType', 'getPriceType', 'getPrice', 'getImageSizeX', 'getImageSizeY','__wakeup'];
        $this->valueMock = $this->createPartialMock(Option::class, $methods);
        $this->validator = new File(
            $configMock,
            $priceConfigMock,
            $this->localeFormatMock
        );
    }

    /**
     * @return void
     */
    public function testIsValidSuccess(): void
    {
        $this->valueMock->expects($this->once())->method('getTitle')->willReturn('option_title');
        $this->valueMock->expects($this->exactly(2))->method('getType')->willReturn('name 1.1');
        $this->valueMock->method('getPriceType')
            ->willReturn('fixed');
        $this->valueMock->method('getPrice')
            ->willReturn(10);
        $this->valueMock->expects($this->once())->method('getImageSizeX')->willReturn(10);
        $this->valueMock->expects($this->once())->method('getImageSizeY')->willReturn(15);
        $this->localeFormatMock
            ->method('getNumber')
            ->withConsecutive([10], [], [15])
            ->willReturnOnConsecutiveCalls(10, null, 15);
        $this->assertEmpty($this->validator->getMessages());
        $this->assertTrue($this->validator->isValid($this->valueMock));
    }

    /**
     * @return void
     */
    public function testIsValidWithNegativeImageSize(): void
    {
        $this->valueMock->expects($this->once())->method('getTitle')->willReturn('option_title');
        $this->valueMock->expects($this->exactly(2))->method('getType')->willReturn('name 1.1');
        $this->valueMock->method('getPriceType')
            ->willReturn('fixed');
        $this->valueMock->method('getPrice')
            ->willReturn(10);
        $this->valueMock->expects($this->once())->method('getImageSizeX')->willReturn(-10);
        $this->valueMock->expects($this->never())->method('getImageSizeY');
        $this->localeFormatMock
            ->method('getNumber')
            ->withConsecutive([10], [-10])
            ->willReturnOnConsecutiveCalls(10, -10);

        $messages = [
            'option values' => 'Invalid option value',
        ];
        $this->assertFalse($this->validator->isValid($this->valueMock));
        $this->assertEquals($messages, $this->validator->getMessages());
    }

    /**
     * @return void
     */
    public function testIsValidWithNegativeImageSizeY(): void
    {
        $this->valueMock->expects($this->once())->method('getTitle')->willReturn('option_title');
        $this->valueMock->expects($this->exactly(2))->method('getType')->willReturn('name 1.1');
        $this->valueMock->method('getPriceType')
            ->willReturn('fixed');
        $this->valueMock->method('getPrice')
            ->willReturn(10);
        $this->valueMock->expects($this->once())->method('getImageSizeX')->willReturn(10);
        $this->valueMock->expects($this->once())->method('getImageSizeY')->willReturn(-10);
        $this->localeFormatMock
            ->method('getNumber')
            ->withConsecutive([10], [], [-10])
            ->willReturnOnConsecutiveCalls(10, null, -10);
        $messages = [
            'option values' => 'Invalid option value',
        ];
        $this->assertFalse($this->validator->isValid($this->valueMock));
        $this->assertEquals($messages, $this->validator->getMessages());
    }
}
