<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Setup\Test\Unit\Console\Command;

use Magento\Setup\Console\Command\MaintenanceStatusCommand;
use Symfony\Component\Console\Tester\CommandTester;

class MaintenanceStatusCommandTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\App\MaintenanceMode|\PHPUnit\Framework\MockObject\MockObject
     */
    private $maintenanceMode;

    /**
     * @var MaintenanceStatusCommand
     */
    private $command;

    protected function setUp(): void
    {
        $this->maintenanceMode = $this->createMock(\Magento\Framework\App\MaintenanceMode::class);
        $this->command = new MaintenanceStatusCommand($this->maintenanceMode);
    }

    /**
     * @param array $maintenanceData
     * @param string $expectedMessage
     * @dataProvider executeDataProvider
     */
    public function testExecute(array $maintenanceData, $expectedMessage)
    {
        $this->maintenanceMode->expects($this->once())->method('isOn')->willReturn($maintenanceData[0]);
        $this->maintenanceMode->expects($this->once())->method('getAddressInfo')->willReturn($maintenanceData[1]);
        $tester = new CommandTester($this->command);
        $tester->execute([]);
        $this->assertEquals($expectedMessage, $tester->getDisplay());
    }

    /**
     * return array
     */
    public function executeDataProvider()
    {
        return [
            [
                [true, ['127.0.0.1', '127.0.0.2']],
                'Status: maintenance mode is active' . PHP_EOL .
                'List of exempt IP-addresses: 127.0.0.1 127.0.0.2' . PHP_EOL
            ],
            [
                [true, []],
                'Status: maintenance mode is active' . PHP_EOL . 'List of exempt IP-addresses: none' . PHP_EOL
            ],
            [
                [false, []],
                'Status: maintenance mode is not active' . PHP_EOL . 'List of exempt IP-addresses: none' . PHP_EOL
            ],
            [
                [false, ['127.0.0.1', '127.0.0.2']],
                'Status: maintenance mode is not active' . PHP_EOL .
                'List of exempt IP-addresses: 127.0.0.1 127.0.0.2' . PHP_EOL
            ],
        ];
    }
}
