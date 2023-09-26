<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\CatalogSearch\Model\Indexer\Scope;

/**
 * Implementation of IndexScopeResolverInterface which resolves index scope dynamically depending on current scope state
 *
 * @deprecated mysql search engine has been removed
 * @see \Magento\Elasticsearch
 */
class ScopeProxy implements \Magento\Framework\Search\Request\IndexScopeResolverInterface
{
    /**
     * Object Manager instance
     *
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var array
     */
    private $states = [];

    /**
     * @var State
     */
    private $scopeState;

    /**
     * Factory constructor
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param State $scopeState
     * @param array $states
     */
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        State $scopeState,
        array $states
    ) {
        $this->objectManager = $objectManager;
        $this->scopeState = $scopeState;
        $this->states = $states;
    }

    /**
     * Creates class instance with specified parameters
     *
     * @param string $state
     * @return \Magento\Framework\Search\Request\IndexScopeResolverInterface
     * @throws UnknownStateException
     */
    private function create($state)
    {
        if (!array_key_exists($state, $this->states)) {
            throw new UnknownStateException(__("Requested resolver for unknown indexer state: $state"));
        }
        return $this->objectManager->create($this->states[$state]);
    }

    /**
     * @inheritdoc
     */
    public function resolve($index, array $dimensions)
    {
        return $this->create($this->scopeState->getState())->resolve($index, $dimensions);
    }
}
