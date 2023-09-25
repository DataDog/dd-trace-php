<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Stdlib\Cookie;

/**
 * CookieScope is used to store default scope metadata.
 */
class CookieScope implements CookieScopeInterface
{
    /**
     * @var SensitiveCookieMetadata
     */
    private $sensitiveCookieMetadata;

    /**
     * @var PublicCookieMetadata
     */
    private $publicCookieMetadata;

    /**
     * @var CookieMetadata
     */
    private $cookieMetadata;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param SensitiveCookieMetadata $sensitiveCookieMetadata
     * @param PublicCookieMetadata $publicCookieMetadata
     * @param CookieMetadata $deleteCookieMetadata
     */
    public function __construct(
        CookieMetadataFactory $cookieMetadataFactory,
        SensitiveCookieMetadata $sensitiveCookieMetadata = null,
        PublicCookieMetadata $publicCookieMetadata = null,
        CookieMetadata $deleteCookieMetadata = null
    ) {
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->sensitiveCookieMetadata = $sensitiveCookieMetadata;
        $this->publicCookieMetadata = $publicCookieMetadata;
        $this->cookieMetadata = $deleteCookieMetadata;
    }

    /**
     * Merges the input override metadata with any defaults set on this Scope, and then returns a CookieMetadata
     * object representing the merged values.
     *
     * @param SensitiveCookieMetadata|null $override
     * @return SensitiveCookieMetadata
     */
    public function getSensitiveCookieMetadata(SensitiveCookieMetadata $override = null)
    {
        if ($this->sensitiveCookieMetadata !== null) {
            $merged = $this->sensitiveCookieMetadata->__toArray();
        } else {
            $merged = [];
        }
        if ($override !== null) {
            $merged = array_merge($merged, $override->__toArray());
        }

        return $this->cookieMetadataFactory->createSensitiveCookieMetadata($merged);
    }

    /**
     * Merges the input override metadata with any defaults set on this Scope, and then returns a CookieMetadata
     * object representing the merged values.
     *
     * @param PublicCookieMetadata|null $override
     * @return PublicCookieMetadata
     */
    public function getPublicCookieMetadata(PublicCookieMetadata $override = null)
    {
        if ($this->publicCookieMetadata !== null) {
            $merged = $this->publicCookieMetadata->__toArray();
        } else {
            $merged = [];
        }
        if ($override !== null) {
            $merged = array_merge($merged, $override->__toArray());
        }

        return $this->cookieMetadataFactory->createPublicCookieMetadata($merged);
    }

    /**
     * Merges the input override metadata with any defaults set on this Scope, and then returns a CookieMetadata
     * object representing the merged values.
     *
     * @param CookieMetadata|null $override
     * @return CookieMetadata
     */
    public function getCookieMetadata(CookieMetadata $override = null)
    {
        if ($this->cookieMetadata !== null) {
            $merged = $this->cookieMetadata->__toArray();
        } else {
            $merged = [];
        }
        if ($override !== null) {
            $merged = array_merge($merged, $override->__toArray());
        }

        return $this->cookieMetadataFactory->createCookieMetadata($merged);
    }
}
