<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Wishlist\Test\Unit\Plugin\Ui\DataProvider;

use Magento\Catalog\Ui\DataProvider\Product\Listing\DataProvider;
use Magento\Wishlist\Helper\Data;
use Magento\Wishlist\Plugin\Ui\DataProvider\WishlistSettings;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers \Magento\Wishlist\Plugin\Ui\DataProvider\WishlistSettings
 */
class WishlistSettingsTest extends TestCase
{
    /**
     * Testable Object
     *
     * @var WishlistSettings
     */
    private $wishlistSettings;

    /**
     * @var Data|MockObject
     */
    private $helperMock;

    /**
     * Set Up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->wishlistSettings = new WishlistSettings($this->helperMock);
    }

    /**
     * Test afterGetData method
     *
     * @return void
     */
    public function testAfterGetData()
    {
        /** @var DataProvider|MockObject $subjectMock */
        $subjectMock = $this->createMock(DataProvider::class);
        $result = [];
        $isAllow = true;
        $this->helperMock->expects($this->once())->method('isAllow')->willReturn(true);

        $expected = ['allowWishlist' => $isAllow];
        $actual = $this->wishlistSettings->afterGetData($subjectMock, $result);
        self::assertEquals($expected, $actual);
    }
}
