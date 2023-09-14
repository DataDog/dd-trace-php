<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Ui\Test\Unit\Component\Form\Field;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\Processor;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\Form\Element\Multiline;
use Magento\Ui\Component\Form\Field;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Class MultilineTest
 *
 * Test for class \Magento\Ui\Component\Form\Element\Multiline
 */
class MultilineTest extends TestCase
{
    const NAME = 'test-name';

    /**
     * @var Multiline
     */
    protected $multiline;

    /**
     * @var UiComponentFactory|MockObject
     */
    protected $uiComponentFactoryMock;

    /**
     * @var ContextInterface|MockObject
     */
    protected $contextMock;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->uiComponentFactoryMock = $this->getMockBuilder(UiComponentFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock = $this->getMockBuilder(ContextInterface::class)
            ->getMockForAbstractClass();
        $this->multiline = new Multiline(
            $this->contextMock,
            $this->uiComponentFactoryMock
        );
    }

    /**
     * Run test for prepare method
     *
     * @param array $data
     * @return void
     *
     * @dataProvider prepareDataProvider
     */
    public function testPrepare(array $data)
    {
        $processor = $this->getMockBuilder(Processor::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->contextMock->expects($this->atLeastOnce())->method('getProcessor')->willReturn($processor);
        $this->uiComponentFactoryMock->expects($this->exactly($data['config']['size']))
            ->method('create')
            ->with($this->stringContains(self::NAME . '_'), Field::NAME, $this->logicalNot($this->isEmpty()))
            ->willReturn($this->getComponentMock($data['config']['size']));

        $this->multiline->setData($data);
        $this->multiline->prepare();

        $result = $this->multiline->getData();

        $this->assertEquals($data, $result);
    }

    /**
     * @param int $exactly
     * @return UiComponentInterface|MockObject
     */
    protected function getComponentMock($exactly)
    {
        $componentMock = $this->getMockBuilder(UiComponentInterface::class)
            ->getMockForAbstractClass();

        $componentMock->expects($this->exactly($exactly))
            ->method('prepare');

        return $componentMock;
    }

    /**
     * Data provider for testPrepare
     *
     * @return array
     */
    public function prepareDataProvider()
    {
        return [
            [
                'data' => [
                    'name' => self::NAME,
                    'config' => [
                        'size' => 2,
                    ]
                ],
            ],
            [
                'data' => [
                    'name' => self::NAME,
                    'config' => [
                        'size' => 3,
                    ]
                ],
            ],
            [
                'data' => [
                    'name' => self::NAME,
                    'config' => [
                        'size' => 1,
                    ]
                ],
            ],
            [
                'data' => [
                    'name' => self::NAME,
                    'config' => [
                        'size' => 5,
                    ]
                ],
            ],
        ];
    }
}
