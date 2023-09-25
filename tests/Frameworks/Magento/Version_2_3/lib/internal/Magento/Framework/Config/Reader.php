<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Config;

use Magento\Framework\Exception\LocalizedException;

/**
 * Read config from different sources and aggregate them
 *
 * @package Magento\Framework\Config
 */
class Reader implements \Magento\Framework\App\Config\Scope\ReaderInterface
{
    /**
     * @var array
     */
    private $sources;

    /**
     * @param array $sources
     */
    public function __construct(array $sources)
    {
        $this->sources = $this->prepareSources($sources);
    }

    /**
     * Read configuration data
     *
     * @param null|string $scope
     * @throws LocalizedException Exception is thrown when scope other than default is given
     * @return array
     */
    public function read($scope = null)
    {
        $config = [];
        foreach ($this->sources as $sourceData) {
            /** @var \Magento\Framework\App\Config\Reader\Source\SourceInterface $source */
            $source = $sourceData['class'];
            $config = array_replace_recursive($config, $source->get($scope));
        }

        return $config;
    }

    /**
     * Prepare source for usage
     *
     * @param array $array
     * @return array
     */
    private function prepareSources(array $array)
    {
        $array = array_filter(
            $array,
            function ($item) {
                return (!isset($item['disable']) || !$item['disable']) && $item['class'];
            }
        );
        uasort(
            $array,
            function ($firstItem, $nexItem) {
                return (int)$firstItem['sortOrder'] <=> (int)$nexItem['sortOrder'];
            }
        );

        return $array;
    }
}
