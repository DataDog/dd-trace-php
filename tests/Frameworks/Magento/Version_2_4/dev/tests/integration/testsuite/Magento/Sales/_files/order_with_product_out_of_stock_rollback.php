<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;

Resolver::getInstance()->requireDataFixture('Magento/Customer/_files/customer_rollback.php');
Resolver::getInstance()->requireDataFixture(
    'Magento/Catalog/_files/product_simple_without_custom_options_rollback.php'
);
Resolver::getInstance()->requireDataFixture('Magento/Sales/_files/default_rollback.php');
