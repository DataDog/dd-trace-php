<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/**
 * @deprecated use @magentoConfigFixture instead.
 */
declare(strict_types=1);

use Magento\Framework\App\Config\Storage\Writer;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();
/** @var Writer  $configWriter */
$configWriter = $objectManager->create(WriterInterface::class);

$configWriter->delete('payment/banktransfer/active');
$configWriter->delete('payment/cashondelivery/active');
$configWriter->delete('payment/checkmo/active');
$configWriter->delete('payment/purchaseorder/active');
$configWriter->delete('payment/authorizenet_acceptjs/active');
