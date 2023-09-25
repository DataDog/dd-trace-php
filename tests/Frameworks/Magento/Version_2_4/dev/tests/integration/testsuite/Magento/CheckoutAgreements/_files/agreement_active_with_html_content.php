<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

use Magento\CheckoutAgreements\Model\Agreement;
use Magento\CheckoutAgreements\Model\ResourceModel\Agreement as AgreementResource;
use Magento\TestFramework\Helper\Bootstrap;

$objectManager = Bootstrap::getObjectManager();

/**
 * @var $agreement         Agreement
 * @var $agreementResource AgreementResource
 */
$agreement = $objectManager->create(Agreement::class);
$agreementResource = $objectManager->create(AgreementResource::class);

$agreement->setData([
    'name' => 'Checkout Agreement (active)',
    'content' => 'Checkout agreement content: <b>HTML</b>',
    'content_height' => '200px',
    'checkbox_text' => 'Checkout agreement checkbox text.',
    'is_active' => true,
    'is_html' => true,
    'stores' => [0, 1],
]);
$agreementResource->save($agreement);
