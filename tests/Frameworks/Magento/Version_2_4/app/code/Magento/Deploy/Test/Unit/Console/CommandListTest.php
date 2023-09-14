<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Deploy\Test\Unit\Console;

use Magento\Deploy\Console\Command\App\ConfigImportCommand;
use Magento\Deploy\Console\CommandList;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @inheritdoc
 */
class CommandListTest extends TestCase
{
    /**
     * @var CommandList
     */
    private $model;

    /**
     * @var ObjectManagerInterface|MockObject
     */
    private $objectManagerMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManagerMock = $this->getMockBuilder(ObjectManagerInterface::class)
            ->getMockForAbstractClass();

        $this->model = new CommandList(
            $this->objectManagerMock
        );
    }

    public function testGetCommands()
    {
        $configImportCommand = $this->getMockBuilder(ConfigImportCommand::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->objectManagerMock->expects($this->once())
            ->method('get')
            ->willReturnMap([
                [ConfigImportCommand::class, $configImportCommand],
            ]);

        $this->assertSame(
            [$configImportCommand],
            $this->model->getCommands()
        );
    }
}
