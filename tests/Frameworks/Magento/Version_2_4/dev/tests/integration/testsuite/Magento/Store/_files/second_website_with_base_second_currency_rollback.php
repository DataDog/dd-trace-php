<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

$objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
/** @var \Magento\Config\Model\ResourceModel\Config $configResource */
$configResource = $objectManager->get(\Magento\Config\Model\ResourceModel\Config::class);
$configResource->deleteConfig(
    \Magento\Catalog\Helper\Data::XML_PATH_PRICE_SCOPE,
    'default',
    0
);
$website = $objectManager->create(\Magento\Store\Model\Website::class);
/** @var $website \Magento\Store\Model\Website */
$websiteId = $website->load('test', 'code')->getId();
if ($websiteId) {
    $configResource->deleteConfig(
        \Magento\Directory\Model\Currency::XML_PATH_CURRENCY_BASE,
        \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES,
        $websiteId
    );
}

Resolver::getInstance()->requireDataFixture('Magento/Store/_files/second_website_with_two_stores_rollback.php');
