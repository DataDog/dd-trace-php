<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Store\Model\Store;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/**
 * @var $block Block
 * @var $blockRepository BlockRepositoryInterface
 */
$block = $objectManager->create(Block::class);
$blockRepository = $objectManager->create(BlockRepositoryInterface::class);

$block->setTitle(
    'CMS Block Title'
)->setIdentifier(
    'default_store_block'
)->setContent(
    '<h1>Fixture Block Title</h1>
<a href="{{store url=""}}">store url</a>
<p>Config value: "{{config path="web/unsecure/base_url"}}".</p>
<p>Custom variable: "{{customvar code="variable_code"}}".</p>'
)->setIsActive(
    1
)->setStores(
    [Store::DEFAULT_STORE_ID]
);

$blockRepository->save($block);
