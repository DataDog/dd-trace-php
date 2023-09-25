<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Test\Di;

require_once __DIR__ . '/DiParent.php';
require_once __DIR__ . '/ChildInterface.php';
class Child extends \Magento\Test\Di\DiParent implements \Magento\Test\Di\ChildInterface
{
}
