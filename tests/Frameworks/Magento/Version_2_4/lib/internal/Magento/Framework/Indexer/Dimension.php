<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Indexer;

/**
 * Index Dimension object
 *
 * @api
 * @since 101.0.6
 */
class Dimension
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $value;

    /**
     * @param string $name
     * @param string $value
     */
    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    /**
     * Get dimension name
     *
     * @return string
     * @since 101.0.6
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get dimension value
     *
     * @return string
     * @since 101.0.6
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
