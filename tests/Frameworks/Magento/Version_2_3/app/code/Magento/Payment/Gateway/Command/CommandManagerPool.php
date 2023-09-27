<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Gateway\Command;

use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\ObjectManager\TMap;
use Magento\Framework\ObjectManager\TMapFactory;

/**
 * Class CommandManagerPool
 * @package Magento\Payment\Gateway\Command
 * @api
 * @since 100.1.0
 */
class CommandManagerPool implements CommandManagerPoolInterface
{
    /**
     * @var CommandManagerInterface[] | TMap
     */
    private $executors;

    /**
     * @param TMapFactory $tmapFactory
     * @param array $executors
     */
    public function __construct(
        TMapFactory $tmapFactory,
        array $executors = []
    ) {
        $this->executors = $tmapFactory->createSharedObjectsMap(
            [
                'array' => $executors,
                'type' => CommandManagerInterface::class
            ]
        );
    }

    /**
     * Returns Command executor for defined payment provider
     *
     * @param string $paymentProviderCode
     * @return CommandManagerInterface
     * @throws NotFoundException
     * @since 100.1.0
     */
    public function get($paymentProviderCode)
    {
        if (!isset($this->executors[$paymentProviderCode])) {
            throw new NotFoundException(
                __('The "%1" command executor isn\'t defined. Verify the executor and try again.', $paymentProviderCode)
            );
        }

        return $this->executors[$paymentProviderCode];
    }
}
