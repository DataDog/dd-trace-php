<?php
/**
 * Magento filesystem zlib driver
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Filesystem\Driver;

class Zlib extends File
{
    /**
     * @var string
     */
    protected $scheme = 'compress.zlib';
}
