<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Test\Integrity\Modular;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;

class SystemConfigFilesTest extends \PHPUnit\Framework\TestCase
{
    public function testConfiguration()
    {
        $objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        // disable config caching to not pollute it
        /** @var $cacheState \Magento\Framework\App\Cache\StateInterface */
        $cacheState = $objectManager->get(\Magento\Framework\App\Cache\StateInterface::class);
        $cacheState->setEnabled(\Magento\Framework\App\Cache\Type\Config::TYPE_IDENTIFIER, false);

        /** @var \Magento\Framework\Filesystem $filesystem */
        $filesystem = $objectManager->get(\Magento\Framework\Filesystem::class);
        $modulesDir = $filesystem->getDirectoryRead(DirectoryList::ROOT);
        /** @var $moduleDirSearch \Magento\Framework\Component\DirSearch */
        $moduleDirSearch = $objectManager->get(\Magento\Framework\Component\DirSearch::class);
        $fileList = $moduleDirSearch
            ->collectFiles(ComponentRegistrar::MODULE, 'etc/adminhtml/system.xml');
        $configMock = $this->createPartialMock(
            \Magento\Framework\Module\Dir\Reader::class,
            ['getConfigurationFiles', 'getModuleDir']
        );
        $configMock->expects($this->any())->method('getConfigurationFiles')->willReturn($fileList);
        $configMock->expects(
            $this->any()
        )->method(
            'getModuleDir'
        )->with(
            'etc',
            'Magento_Backend'
        )->willReturn(
            $modulesDir->getAbsolutePath() . '/app/code/Magento/Backend/etc'
        );
        try {
            $objectManager->create(
                \Magento\Config\Model\Config\Structure\Reader::class,
                ['moduleReader' => $configMock, 'runtimeValidation' => true]
            );
        } catch (\Magento\Framework\Exception\LocalizedException $exp) {
            $this->fail($exp->getMessage());
        }
    }
}
