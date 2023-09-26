<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\Jwt\Claim;

use Magento\Framework\Jwt\ClaimInterface;

/**
 * "exp" claim.
 */
class ExpirationTime implements ClaimInterface
{
    /**
     * @var string
     */
    private $value;

    /**
     * @var bool
     */
    private $duplicate;

    /**
     * @param \DateTimeInterface $value
     * @param bool $duplicate
     */
    public function __construct(\DateTimeInterface $value, bool $duplicate = false)
    {
        $this->value = $value->getTimestamp();
        $this->duplicate = $duplicate;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'exp';
    }

    /**
     * @inheritDoc
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @inheritDoc
     */
    public function getClass(): ?int
    {
        return self::CLASS_REGISTERED;
    }

    /**
     * @inheritDoc
     */
    public function isHeaderDuplicated(): bool
    {
        return $this->duplicate;
    }
}
