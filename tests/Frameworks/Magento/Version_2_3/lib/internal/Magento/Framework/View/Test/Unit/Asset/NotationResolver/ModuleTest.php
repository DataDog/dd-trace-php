<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\View\Test\Unit\Asset\NotationResolver;

class ModuleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\View\Asset\File|\PHPUnit\Framework\MockObject\MockObject
     */
    private $asset;

    /**
     * @var \Magento\Framework\View\Asset\Repository|\PHPUnit\Framework\MockObject\MockObject
     */
    private $assetRepo;

    /**
     * @var \Magento\Framework\View\Asset\NotationResolver\Module;
     */
    private $object;

    protected function setUp(): void
    {
        $this->asset = $this->createMock(\Magento\Framework\View\Asset\File::class);
        $this->assetRepo = $this->createPartialMock(
            \Magento\Framework\View\Asset\Repository::class,
            ['createUsingContext', 'createSimilar']
        );
        $this->object = new \Magento\Framework\View\Asset\NotationResolver\Module($this->assetRepo);
    }

    public function testConvertModuleNotationToPathNoModularSeparator()
    {
        $this->asset->expects($this->never())->method('getPath');
        $this->assetRepo->expects($this->never())->method('createUsingContext');
        $textNoSeparator = 'name_without_double_colon.ext';
        $this->assertEquals(
            $textNoSeparator,
            $this->object->convertModuleNotationToPath($this->asset, $textNoSeparator)
        );
    }

    /**
     * @param string $assetRelPath
     * @param string $relatedFieldId
     * @param string $similarRelPath
     * @param string $expectedResult
     * @dataProvider convertModuleNotationToPathModularSeparatorDataProvider
     */
    public function testConvertModuleNotationToPathModularSeparator(
        $assetRelPath,
        $relatedFieldId,
        $similarRelPath,
        $expectedResult
    ) {
        $similarAsset = $this->createMock(\Magento\Framework\View\Asset\File::class);
        $similarAsset->expects($this->any())
            ->method('getPath')
            ->willReturn($similarRelPath);
        $this->asset->expects($this->once())
            ->method('getPath')
            ->willReturn($assetRelPath);
        $this->assetRepo->expects($this->once())
            ->method('createSimilar')
            ->with($relatedFieldId, $this->asset)
            ->willReturn($similarAsset);
        $this->assertEquals(
            $expectedResult,
            $this->object->convertModuleNotationToPath($this->asset, $relatedFieldId)
        );
    }

    /**
     * @return array
     */
    public function convertModuleNotationToPathModularSeparatorDataProvider()
    {
        return [
            'same module' => [
                'area/theme/locale/Foo_Bar/styles/style.css',
                'Foo_Bar::images/logo.gif',
                'area/theme/locale/Foo_Bar/images/logo.gif',
                '../images/logo.gif',
            ],
            'non-modular refers to modular' => [
                'area/theme/locale/css/admin.css',
                'Bar_Baz::images/logo.gif',
                'area/theme/locale/Bar_Baz/images/logo.gif',
                '../Bar_Baz/images/logo.gif',
            ],
            'different modules' => [
                'area/theme/locale/Foo_Bar/styles/style.css',
                'Bar_Baz::images/logo.gif',
                'area/theme/locale/Bar_Baz/images/logo.gif',
                '../../Bar_Baz/images/logo.gif',
            ]
        ];
    }
}
