<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\RequireJs\Test\Unit;

use \Magento\Framework\RequireJs\Config;
use Magento\Framework\View\Asset\RepositoryMap;

class ConfigTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\RequireJs\Config\File\Collector\Aggregated|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fileSource;

    /**
     * @var \Magento\Framework\View\DesignInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $design;

    /**
     * @var \Magento\Framework\Filesystem\File\Read|\PHPUnit\Framework\MockObject\MockObject
     */
    private $fileReader;

    /**
     * @var \Magento\Framework\View\Asset\ContextInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $context;

    /**
     * @var Config
     */
    private $object;

    /**
     * @var \Magento\Framework\View\Asset\Minification|\PHPUnit\Framework\MockObject\MockObject
     */
    private $minificationMock;

    /**
     * @var \Magento\Framework\Code\Minifier\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $minifyAdapterMock;

    /**
     * @var RepositoryMap|\PHPUnit\Framework\MockObject\MockObject
     */
    private $repositoryMapMock;

    protected function setUp(): void
    {
        $this->fileSource = $this->createMock(\Magento\Framework\RequireJs\Config\File\Collector\Aggregated::class);
        $this->design = $this->getMockForAbstractClass(\Magento\Framework\View\DesignInterface::class);

        $readFactory = $this->createMock(\Magento\Framework\Filesystem\File\ReadFactory::class);
        $this->fileReader = $this->createMock(\Magento\Framework\Filesystem\File\Read::class);
        $readFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->fileReader);
        $repo = $this->createMock(\Magento\Framework\View\Asset\Repository::class);
        $this->context = $this->getMockBuilder(\Magento\Framework\View\Asset\ContextInterface::class)
            ->setMethods(
                [
                    'getConfigPath',
                    'getPath',
                    'getBaseUrl',
                    'getAreaCode',
                    'getThemePath',
                    'getLocale'
                ]
            )
            ->getMock();
        $repo->expects($this->once())->method('getStaticViewFileContext')->willReturn($this->context);
        $this->minificationMock = $this->getMockBuilder(\Magento\Framework\View\Asset\Minification::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->minifyAdapterMock = $this->getMockBuilder(\Magento\Framework\Code\Minifier\AdapterInterface::class)
            ->getMockForAbstractClass();

        $this->repositoryMapMock = $this->getMockBuilder(RepositoryMap::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->object = new Config(
            $this->fileSource,
            $this->design,
            $readFactory,
            $repo,
            $this->minifyAdapterMock,
            $this->minificationMock,
            $this->repositoryMapMock
        );
    }

    public function testGetConfig()
    {
        $this->fileReader->expects($this->any())
            ->method('readAll')
            ->willReturnCallback(
                
                    function ($file) {
                        return $file . ' content';
                    }
                
            );
        $fileOne = $this->createMock(\Magento\Framework\View\File::class);
        $fileOne->expects($this->once())
            ->method('getFilename')
            ->willReturn('some/full/relative/path/file_one.js');
        $fileOne->expects($this->once())
            ->method('getName')
            ->willReturn('file_one.js');
        $fileOne->expects($this->once())
            ->method('getModule')
            ->willReturn('Module_One');
        $fileTwo = $this->createMock(\Magento\Framework\View\File::class);
        $fileTwo->expects($this->once())
            ->method('getFilename')
            ->willReturn('some/full/relative/path/file_two.js');
        $fileTwo->expects($this->once())
            ->method('getName')
            ->willReturn('file_two.js');
        $theme = $this->getMockForAbstractClass(\Magento\Framework\View\Design\ThemeInterface::class);
        $this->design->expects($this->once())
            ->method('getDesignTheme')
            ->willReturn($theme);
        $this->fileSource->expects($this->once())
            ->method('getFiles')
            ->with($theme, Config::CONFIG_FILE_NAME)
            ->willReturn([$fileOne, $fileTwo]);
        $this->minificationMock
            ->expects($this->atLeastOnce())
            ->method('isEnabled')
            ->with('js')
            ->willReturn(true);

        $expected = <<<expected
(function(require){
(function() {
file_one.js content
require.config(config);
})();
(function() {
file_two.js content
require.config(config);
})();



})(require);
expected;

        $this->minifyAdapterMock
            ->expects($this->once())
            ->method('minify')
            ->with($expected)
            ->willReturnArgument(0);

        $actual = $this->object->getConfig();
        $this->assertEquals($actual, $expected);
    }

    public function testGetMinResolverCode()
    {
        $this->minificationMock
            ->expects($this->once())
            ->method('getExcludes')
            ->with('js')
            ->willReturn(['\.min\.']);
        $this->minificationMock
            ->expects($this->once())
            ->method('isEnabled')
            ->with('js')
            ->willReturn(true);
        $this->minifyAdapterMock
            ->expects($this->once())
            ->method('minify')
            ->willReturnArgument(0);

        $expected = <<<code
    var ctx = require.s.contexts._,
        origNameToUrl = ctx.nameToUrl,
        baseUrl = ctx.config.baseUrl;

    ctx.nameToUrl = function() {
        var url = origNameToUrl.apply(ctx, arguments);
        if (url.indexOf(baseUrl) === 0&&!url.match(/\.min\./)) {
            url = url.replace(/(\.min)?\.js$/, '.min.js');
        }
        return url;
    };

code;
        $this->assertEquals($expected, $this->object->getMinResolverCode());
    }

    public function testGetConfigFileRelativePath()
    {
        $this->minificationMock
            ->expects($this->any())
            ->method('addMinifiedSign')
            ->willReturnArgument(0);
        $this->context->expects($this->once())->method('getConfigPath')->willReturn('path');
        $actual = $this->object->getConfigFileRelativePath();
        $this->assertSame('path/requirejs-config.js', $actual);
    }

    public function testGetMixinsFileRelativePath()
    {
        $this->minificationMock
            ->expects($this->any())
            ->method('addMinifiedSign')
            ->willReturnArgument(0);
        $this->context->expects($this->once())->method('getPath')->willReturn('path');
        $actual = $this->object->getMixinsFileRelativePath();
        $this->assertSame('path/mage/requirejs/mixins.js', $actual);
    }

    public function testGetMinResolverRelativePath()
    {
        $this->minificationMock
            ->expects($this->any())
            ->method('addMinifiedSign')
            ->willReturnArgument(0);
        $this->context->expects($this->once())->method('getConfigPath')->willReturn('path');
        $actual = $this->object->getMinResolverRelativePath();
        $this->assertSame('path/requirejs-min-resolver.js', $actual);
    }

    public function testGetBaseConfig()
    {
        $this->context->expects($this->once())->method('getPath')->willReturn('area/theme/locale');
        $this->context->expects($this->once())
            ->method('getBaseUrl')
            ->willReturn('http://base.url/');
        $expected = <<<expected
require.config({"baseUrl":"http://base.url/area/theme/locale"});
expected;
        $actual = $this->object->getBaseConfig();
        $this->assertSame($expected, $actual);
    }
}
