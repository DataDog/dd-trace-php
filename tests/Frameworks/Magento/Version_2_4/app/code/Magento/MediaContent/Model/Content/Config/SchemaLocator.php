<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MediaContent\Model\Content\Config;

use Magento\Framework\Config\SchemaLocatorInterface;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader;

/**
 * Media Content configuration schema locator
 */
class SchemaLocator implements SchemaLocatorInterface
{
    /**
     * Path to corresponding XSD file with validation rules for both individual and merged configs
     *
     * @var string
     */
    private $schema;

    /**
     * @param Reader $moduleReader
     */
    public function __construct(Reader $moduleReader)
    {
        $this->schema = $moduleReader->getModuleDir(Dir::MODULE_ETC_DIR, 'Magento_MediaContentApi')
            . '/media_content.xsd';
    }

    /**
     * Get path to merged config schema
     *
     * @return string|null
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * Get path to per file validation schema
     *
     * @return string|null
     */
    public function getPerFileSchema()
    {
        return $this->schema;
    }
}
