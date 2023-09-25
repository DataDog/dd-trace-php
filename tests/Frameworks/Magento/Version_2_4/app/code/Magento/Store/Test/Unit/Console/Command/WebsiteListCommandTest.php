<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Store\Test\Unit\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Store\Console\Command\WebsiteListCommand;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class WebsiteListCommandTest extends TestCase
{
    /**
     * @var WebsiteListCommand
     */
    private $command;

    /**
     * @var WebsiteRepositoryInterface|MockObject
     */
    private $websiteRepositoryMock;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);

        $this->websiteRepositoryMock = $this->getMockForAbstractClass(WebsiteRepositoryInterface::class);

        $this->command = $this->objectManager->getObject(
            WebsiteListCommand::class,
            ['websiteManagement' => $this->websiteRepositoryMock]
        );
    }

    public function testExecuteExceptionNoVerbosity()
    {
        $this->websiteRepositoryMock->expects($this->any())
            ->method('getList')
            ->willThrowException(new \Exception("Dummy test exception"));

        $tester = new CommandTester($this->command);
        $this->assertEquals(Cli::RETURN_FAILURE, $tester->execute([]));

        $linesOutput = array_filter(explode(PHP_EOL, $tester->getDisplay()));
        $this->assertEquals('Dummy test exception', $linesOutput[0]);
    }

    public function testExecute()
    {
        $websiteData = [
            'id' => '444',
            'default_group_id' => '555',
            'name' => 'unit test website',
            'code' => 'unit_test_website',
            'is_default' => '0',
            'sort_order' => '987',
        ];

        $websites = [
            $this->objectManager->getObject(Website::class)->setData($websiteData),
        ];

        $this->websiteRepositoryMock->expects($this->any())
            ->method('getList')
            ->willReturn($websites);

        $tester = new CommandTester($this->command);
        $this->assertEquals(Cli::RETURN_SUCCESS, $tester->execute([]));

        $linesOutput = array_filter(explode(PHP_EOL, $tester->getDisplay()));
        $this->assertCount(5, $linesOutput, 'There should be 5 lines output. 3 Spacers, 1 header, 1 content.');

        $this->assertEquals($linesOutput[0], $linesOutput[2], "Lines 0, 2, 4 should be spacer lines");
        $this->assertEquals($linesOutput[2], $linesOutput[4], "Lines 0, 2, 4 should be spacer lines");

        $headerValues = array_values(array_filter(explode('|', $linesOutput[1])));
        //trim to remove the whitespace left from the exploding pipe separation
        $this->assertEquals('ID', trim($headerValues[0]));
        $this->assertEquals('Default Group Id', trim($headerValues[1]));
        $this->assertEquals('Name', trim($headerValues[2]));
        $this->assertEquals('Code', trim($headerValues[3]));
        $this->assertEquals('Sort Order', trim($headerValues[4]));
        $this->assertEquals('Is Default', trim($headerValues[5]));

        $websiteValues = array_values(array_filter(explode('|', $linesOutput[3])));
        $this->assertEquals('444', trim($websiteValues[0]));
        $this->assertEquals('555', trim($websiteValues[1]));
        $this->assertEquals('unit test website', trim($websiteValues[2]));
        $this->assertEquals('unit_test_website', trim($websiteValues[3]));
        $this->assertEquals('987', trim($websiteValues[4]));
        $this->assertEquals('0', trim($websiteValues[5]));
    }
}
