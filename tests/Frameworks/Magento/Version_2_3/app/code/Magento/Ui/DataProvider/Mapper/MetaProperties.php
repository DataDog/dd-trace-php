<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Ui\DataProvider\Mapper;

/**
 * Class MetaProperties
 */
class MetaProperties implements MapperInterface
{
    /**
     * @var array
     */
    protected $mappings = [];

    /**
     * @param array $mappings
     */
    public function __construct(array $mappings)
    {
        $this->mappings = $mappings;
    }

    /**
     * Retrieve mappings
     *
     * @return array
     */
    public function getMappings()
    {
        return $this->mappings;
    }
}
