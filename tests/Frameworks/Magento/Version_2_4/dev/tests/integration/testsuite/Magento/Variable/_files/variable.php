<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

$variable = \Magento\TestFramework\Helper\Bootstrap::getObjectManager()->create(
    \Magento\Variable\Model\Variable::class
);
$variable->setCode(
    'variable_code'
)->setName(
    'Variable Name'
)->setPlainValue(
    'Plain Value'
)->setHtmlValue(
    'HTML Value'
)->save();
