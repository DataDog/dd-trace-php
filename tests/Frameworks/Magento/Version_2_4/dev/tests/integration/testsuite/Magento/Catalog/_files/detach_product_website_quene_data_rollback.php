<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\TestFramework\MessageQueue\ClearQueueProcessor;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

$objectManager = Bootstrap::getObjectManager();
/** @var ClearQueueProcessor $clearQueueProcessor */
$clearQueueProcessor = $objectManager->get(ClearQueueProcessor::class);
$clearQueueProcessor->execute('product_action_attribute.website.update');

Resolver::getInstance()->requireDataFixture('Magento/Catalog/_files/product_with_two_websites_rollback.php');
