<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Webapi\Test\Unit\ServiceInputProcessor;

use Magento\Framework\Api\AbstractExtensibleObject;

class AssociativeArray extends AbstractExtensibleObject
{
    /**
     * @return string[]
     */
    public function getAssociativeArray()
    {
        return $this->_get('associativeArray');
    }

    /**
     * @param string[] $associativeArray
     * @return $this
     */
    public function setAssociativeArray(array $associativeArray = [])
    {
        return $this->setData('associativeArray', $associativeArray);
    }
}
