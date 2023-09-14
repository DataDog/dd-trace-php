<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\View\Test\Unit\Asset\PreProcessor;

use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Asset\PreProcessor\Chain;
use Magento\Framework\View\Asset\PreProcessor\Helper\SortInterface;
use Magento\Framework\View\Asset\PreProcessor\Pool;
use Magento\Framework\View\Asset\PreProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 *
 * @see \Magento\Framework\View\Asset\PreProcessor\Pool
 */
class PoolTest extends TestCase
{
    const DEFAULT_PREPROCESSOR = 'defaul/preprocessor';

    const CONTENT_TYPE = 'test-type';

    const PREPROCESSOR_CLASS = PreProcessorInterface::class;

    /**
     * @var ObjectManagerInterface|MockObject
     */
    private $objectManagerMock;

    /**
     * @var SortInterface|MockObject
     */
    private $sorterMock;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(ObjectManagerInterface::class)
            ->getMockForAbstractClass();
        $this->sorterMock = $this->getMockBuilder(
            SortInterface::class
        )->getMockForAbstractClass();
    }

    /**
     * @return Chain|MockObject
     */
    private function getChainMock($type)
    {
        /** @var Chain|MockObject $chainMock */
        $chainMock = $this->getMockBuilder(Chain::class)
            ->disableOriginalConstructor()
            ->getMock();

        $chainMock->expects(self::once())
            ->method('getTargetContentType')
            ->willReturn($type);

        return $chainMock;
    }

    /**
     * @param Chain|MockObject $chainMock
     * @return PreProcessorInterface|MockObject
     */
    private function getPreprocessorMock($chainMock)
    {
        /** @var PreProcessorInterface|MockObject $preprocessorMock */
        $preprocessorMock = $this->getMockBuilder(self::PREPROCESSOR_CLASS)
            ->getMockForAbstractClass();

        $preprocessorMock->expects(self::once())
            ->method('process')
            ->with($chainMock);

        return $preprocessorMock;
    }

    /**
     * Run test for process method
     */
    public function testProcess()
    {
        $preprocessors = [
            self::CONTENT_TYPE => [
                'test' => [
                    Pool::PREPROCESSOR_CLASS => self::PREPROCESSOR_CLASS
                ]
            ]
        ];

        $pool = new Pool(
            $this->objectManagerMock,
            $this->sorterMock,
            self::DEFAULT_PREPROCESSOR,
            $preprocessors
        );

        $this->sorterMock->expects(self::once())
            ->method('sort')
            ->with($preprocessors[self::CONTENT_TYPE])
            ->willReturn($preprocessors[self::CONTENT_TYPE]);

        $chainMock = $this->getChainMock(self::CONTENT_TYPE);

        $this->objectManagerMock->expects(self::once())
            ->method('get')
            ->with(self::PREPROCESSOR_CLASS)
            ->willReturn($this->getPreprocessorMock($chainMock));

        $pool->process($chainMock);
    }

    /**
     * Run test for process method (default preprocessor)
     */
    public function testProcessDefault()
    {
        $preprocessors = [
            'bad-type' => [],
        ];

        $pool = new Pool(
            $this->objectManagerMock,
            $this->sorterMock,
            self::DEFAULT_PREPROCESSOR,
            $preprocessors
        );

        $this->sorterMock->expects(self::never())
            ->method('sort');

        $chainMock = $this->getChainMock(self::CONTENT_TYPE);

        $this->objectManagerMock->expects(self::once())
            ->method('get')
            ->with(self::DEFAULT_PREPROCESSOR)
            ->willReturn($this->getPreprocessorMock($chainMock));

        $pool->process($chainMock);
    }

    /**
     * Run test for process method (exception)
     */
    public function testProcessBadInterface()
    {
        $this->expectException('UnexpectedValueException');
        $this->expectExceptionMessage('"stdClass" has to implement the PreProcessorInterface.');
        $preprocessors = [
            self::CONTENT_TYPE => [
                'test' => [
                    Pool::PREPROCESSOR_CLASS => 'stdClass'
                ]
            ]
        ];

        $pool = new Pool(
            $this->objectManagerMock,
            $this->sorterMock,
            self::DEFAULT_PREPROCESSOR,
            $preprocessors
        );

        $this->sorterMock->expects(self::once())
            ->method('sort')
            ->with($preprocessors[self::CONTENT_TYPE])
            ->willReturn($preprocessors[self::CONTENT_TYPE]);

        $chainMock = $this->getChainMock(self::CONTENT_TYPE);

        $this->objectManagerMock->expects(self::once())
            ->method('get')
            ->with('stdClass')
            ->willReturn(new \stdClass());

        $pool->process($chainMock);
    }
}
