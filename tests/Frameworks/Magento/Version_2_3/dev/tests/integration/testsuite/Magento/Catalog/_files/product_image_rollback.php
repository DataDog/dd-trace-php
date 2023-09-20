<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\Framework\App\Filesystem\DirectoryList;

/** @var $config \Magento\Catalog\Model\Product\Media\Config */
$config = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
    \Magento\Catalog\Model\Product\Media\Config::class
);
/** @var $database \Magento\MediaStorage\Helper\File\Storage\Database */
$database = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
    \Magento\MediaStorage\Helper\File\Storage\Database::class
);

/** @var \Magento\Framework\Filesystem\Directory\WriteInterface $mediaDirectory */
$mediaDirectory = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->get(
    \Magento\Framework\Filesystem::class
)->getDirectoryWrite(
    DirectoryList::MEDIA
);

$mediaDirectory->delete($config->getBaseMediaPath());
$mediaDirectory->delete($config->getBaseTmpMediaPath());

$database->deleteFolder($config->getBaseMediaPath());
$database->deleteFolder($config->getBaseTmpMediaPath());
