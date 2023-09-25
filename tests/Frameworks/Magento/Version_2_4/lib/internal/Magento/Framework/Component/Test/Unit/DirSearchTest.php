<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Component\Test\Unit;

use Magento\Framework\Component\ComponentFile;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Component\DirSearch;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\DriverPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DirSearchTest extends TestCase
{
    /**
     * @var ReadInterface|MockObject
     */
    private $dir;

    /**
     * @var ComponentRegistrarInterface|MockObject
     */
    private $registrar;

    /**
     * @var ReadFactory|MockObject
     */
    private $readFactory;

    /**
     * @var DirSearch
     */
    private $object;

    protected function setUp(): void
    {
        $this->registrar = $this->getMockForAbstractClass(
            ComponentRegistrarInterface::class
        );
        $this->readFactory = $this->createMock(ReadFactory::class);
        $this->dir = $this->getMockForAbstractClass(ReadInterface::class);
        $this->dir->expects($this->any())
            ->method('getAbsolutePath')
            ->willReturnArgument(0);
        $this->object = new DirSearch($this->registrar, $this->readFactory);
    }

    public function testCollectFilesNothingFound()
    {
        $componentType = 'component_type';
        $this->registrar->expects($this->exactly(2))
            ->method('getPaths')
            ->with($componentType)
            ->willReturn([]);
        $this->readFactory->expects($this->never())
            ->method('create');
        $this->assertSame([], $this->object->collectFiles($componentType, '*/file.xml'));
        $this->assertSame([], $this->object->collectFilesWithContext($componentType, '*/file.xml'));
    }

    public function testCollectFiles()
    {
        $componentType = 'component_type';
        $componentPaths = ['component1' => 'path1', 'component2' => 'path2'];
        $pattern = '*/file.xml';
        $this->registrar->expects($this->once())
            ->method('getPaths')
            ->with($componentType)
            ->willReturn($componentPaths);
        $this->readFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnMap([
                ['path1', DriverPool::FILE, $this->dir],
                ['path2', DriverPool::FILE, $this->dir],
            ]);
        $this->dir->method('search')
            ->with($pattern)
            ->willReturnOnConsecutiveCalls(['one/file.xml'], ['two/file.xml']);
        $expected = ['one/file.xml', 'two/file.xml'];
        $this->assertSame($expected, $this->object->collectFiles($componentType, $pattern));
    }

    public function testCollectFilesWithContext()
    {
        $componentType = 'component_type';
        $componentPaths = ['component1' => 'path1', 'component2' => 'path2'];
        $pattern = '*/file.xml';
        $this->registrar->expects($this->once())
            ->method('getPaths')
            ->with($componentType)
            ->willReturn($componentPaths);
        $this->readFactory->expects($this->exactly(2))
            ->method('create')
            ->willReturnMap([
                ['path1', DriverPool::FILE, $this->dir],
                ['path2', DriverPool::FILE, $this->dir],
            ]);
        $this->dir->method('search')
            ->with($pattern)
            ->willReturnOnConsecutiveCalls(['one/file.xml'], ['two/file.xml']);
        $actualFiles = $this->object->collectFilesWithContext($componentType, $pattern);
        $this->assertNotEmpty($actualFiles);
        /** @var ComponentFile $file */
        foreach ($actualFiles as $file) {
            $this->assertInstanceOf(ComponentFile::class, $file);
            $this->assertSame($componentType, $file->getComponentType());
        }
        $this->assertCount(2, $actualFiles);
        $this->assertSame('component1', $actualFiles[0]->getComponentName());
        $this->assertSame('one/file.xml', $actualFiles[0]->getFullPath());
        $this->assertSame('component2', $actualFiles[1]->getComponentName());
        $this->assertSame('two/file.xml', $actualFiles[1]->getFullPath());
    }
}
