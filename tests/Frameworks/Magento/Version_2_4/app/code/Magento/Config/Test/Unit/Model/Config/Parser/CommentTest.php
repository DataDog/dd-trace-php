<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Config\Test\Unit\Model\Config\Parser;

use Magento\Config\Model\Config\Parser\Comment;
use Magento\Config\Model\Placeholder\PlaceholderInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentTest extends TestCase
{
    /**
     * @var PlaceholderInterface|MockObject
     */
    private $placeholderMock;

    /**
     * @var Filesystem|MockObject
     */
    private $fileSystemMock;

    /**
     * @var Comment
     */
    private $model;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->placeholderMock = $this->getMockBuilder(PlaceholderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->fileSystemMock = $this->getMockBuilder(Filesystem::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Comment(
            $this->fileSystemMock,
            $this->placeholderMock
        );
    }

    public function testExecute()
    {
        $fileName = 'config.local.php';
        $directoryReadMock = $this->getMockBuilder(ReadInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $directoryReadMock->expects($this->once())
            ->method('readFile')
            ->with($fileName)
            ->willReturn(file_get_contents(__DIR__ . '/../_files/' . $fileName));
        $this->fileSystemMock->expects($this->once())
            ->method('getDirectoryRead')
            ->with(DirectoryList::CONFIG)
            ->willReturn($directoryReadMock);
        $this->placeholderMock->expects($this->any())
            ->method('restore')
            ->withConsecutive(
                ['CONFIG__DEFAULT__SOME__PAYMENT__PASSWORD'],
                ['CONFIG__DEFAULT__SOME__PAYMENT__TOKEN']
            )
            ->willReturnOnConsecutiveCalls(
                'some/payment/password',
                'some/payment/token'
            );

        $this->assertEquals(
            $this->model->execute($fileName),
            [
                'CONFIG__DEFAULT__SOME__PAYMENT__PASSWORD' => 'some/payment/password',
                'CONFIG__DEFAULT__SOME__PAYMENT__TOKEN' => 'some/payment/token'
            ]
        );
    }
}
