<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

use Magento\Framework\App\Config\Storage\Writer;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;

$objectManager = Bootstrap::getObjectManager();
/** @var Writer $configWriter */
$configWriter = $objectManager->get(WriterInterface::class);

$configWriter->save('carriers/usps/active', 1);
$configWriter->save(\Magento\Sales\Model\Order\Shipment::XML_PATH_STORE_ZIP, '90210');

$scopeConfig = $objectManager->get(ScopeConfigInterface::class);
$scopeConfig->clean();
