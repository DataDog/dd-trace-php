<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Data\Argument\Interpreter;

use Magento\Framework\Data\Argument\InterpreterInterface;

/**
 * Interpreter that returns value of a constant by its name
 */
class Constant implements InterpreterInterface
{
    /**
     * {@inheritdoc}
     * @return mixed
     * @throws \InvalidArgumentException
     */
    public function evaluate(array $data)
    {
        if (!isset($data['value']) || !defined($data['value'])) {
            throw new \InvalidArgumentException('Constant name is expected.');
        }
        return constant($data['value']);
    }
}
