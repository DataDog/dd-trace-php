<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Cache\Config;

use Magento\Framework\Serialize\SerializerInterface;

/**
 * Provides cached configuration data based on current config scope
 */
class Data extends \Magento\Framework\Config\Data\Scoped
{
    /**
     * Scope priority loading scheme
     *
     * @var string[]
     */
    protected $_scopePriorityScheme = ['global'];

    /**
     * Constructor
     *
     * @param \Magento\Framework\Cache\Config\Reader $reader
     * @param \Magento\Framework\Config\ScopeInterface $configScope
     * @param \Magento\Framework\Config\CacheInterface $cache
     * @param string $cacheId
     * @param SerializerInterface|null $serializer
     */
    public function __construct(
        \Magento\Framework\Cache\Config\Reader $reader,
        \Magento\Framework\Config\ScopeInterface $configScope,
        \Magento\Framework\Config\CacheInterface $cache,
        $cacheId,
        SerializerInterface $serializer = null
    ) {
        parent::__construct($reader, $configScope, $cache, $cacheId, $serializer);
    }
}
