<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Design\FileResolution\Fallback\Resolver;

use \Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Alternative;
use Magento\Framework\App\Filesystem\DirectoryList;

class AlternativeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Filesystem\Directory\Read|\PHPUnit\Framework\MockObject\MockObject
     */
    private $directory;

    /**
     * @var \Magento\Framework\View\Design\Fallback\Rule\RuleInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $rule;

    /**
     * @var \Magento\Framework\View\Design\FileResolution\Fallback\Resolver\Simple
     */
    private $object;

    protected function setUp(): void
    {
        $this->directory = $this->createMock(\Magento\Framework\Filesystem\Directory\Read::class);
        $readFactory = $this->createMock(\Magento\Framework\Filesystem\Directory\ReadFactory::class);
        $readFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->directory);
        $this->rule = $this->createMock(\Magento\Framework\View\Design\Fallback\Rule\RuleInterface::class);
        $rulePool = $this->createMock(\Magento\Framework\View\Design\Fallback\RulePool::class);
        $rulePool->expects($this->any())
            ->method('getRule')
            ->with('type')
            ->willReturn($this->rule);
        $this->object = new Alternative($readFactory, $rulePool, ['css' => ['less']]);
    }

    /**
     * @param array $alternativeExtensions
     *
     * @dataProvider constructorExceptionDataProvider
     */
    public function testConstructorException(array $alternativeExtensions)
    {
        $this->expectException('\InvalidArgumentException');
        $this->expectExceptionMessage("\$alternativeExtensions must be an array with format:"
            . " array('ext1' => array('ext1', 'ext2'), 'ext3' => array(...)]");

        $readFactory = $this->createMock(\Magento\Framework\Filesystem\Directory\ReadFactory::class);
        $rulePool = $this->createMock(\Magento\Framework\View\Design\Fallback\RulePool::class);
        new Alternative($readFactory, $rulePool, $alternativeExtensions);
    }

    /**
     * @return array
     */
    public function constructorExceptionDataProvider()
    {
        return [
            'numerical keys'   => [['css', 'less']],
            'non-array values' => [['css' => 'less']],
        ];
    }

    public function testResolve()
    {
        $requestedFile = 'file.css';
        $expected = 'some/dir/file.less';

        $theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
        $theme->expects($this->any())
            ->method('getFullPath')
            ->willReturn('magento_theme');
        $this->rule->expects($this->atLeastOnce())
            ->method('getPatternDirs')
            ->willReturn(['some/dir']);

        $fileExistsMap = [
            ['file.css', false],
            ['file.less', true],
        ];
        $this->directory->expects($this->any())
            ->method('isExist')
            ->willReturnMap($fileExistsMap);

        $actual = $this->object->resolve('type', $requestedFile, 'frontend', $theme, 'en_US', 'Magento_Module');
        $this->assertSame($expected, $actual);
    }
}
