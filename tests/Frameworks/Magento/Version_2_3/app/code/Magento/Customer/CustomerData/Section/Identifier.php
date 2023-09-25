<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\CustomerData\Section;

/**
 * Customer section identifier
 */
class Identifier
{
    const COOKIE_KEY = 'storage_data_id';

    const SECTION_KEY = 'data_id';

    const UPDATE_MARK = 'sections_updated';

    /**
     * @var int
     */
    protected $markId;

    /**
     * @var \Magento\Framework\Stdlib\Cookie\PhpCookieManager
     */
    protected $cookieManager;

    /**
     * @var \Magento\Framework\Session\Config\ConfigInterface
     */
    protected $sessionConfig;

    /**
     * @param \Magento\Framework\Stdlib\Cookie\PhpCookieManager $cookieManager
     */
    public function __construct(
        \Magento\Framework\Stdlib\Cookie\PhpCookieManager $cookieManager
    ) {
        $this->cookieManager = $cookieManager;
    }

    /**
     * Init mark(identifier) for sections
     *
     * @param bool $forceNewTimestamp
     * @return int
     */
    public function initMark($forceNewTimestamp)
    {
        if ($forceNewTimestamp) {
            $this->markId = time();
            return $this->markId;
        }

        $cookieMarkId = false;
        if (!$this->markId) {
            $cookieMarkId = $this->cookieManager->getCookie(self::COOKIE_KEY);
        }

        $this->markId = $cookieMarkId ? $cookieMarkId : time();

        return $this->markId;
    }

    /**
     * Mark sections with data id
     *
     * @param array $sectionsData
     * @param array|null $sectionNames
     * @param bool $forceNewTimestamp
     * @return array
     */
    public function markSections(array $sectionsData, $sectionNames = null, $forceNewTimestamp = false)
    {
        if (!$sectionNames) {
            $sectionNames = array_keys($sectionsData);
        }
        $markId = $this->initMark($forceNewTimestamp);

        foreach ($sectionNames as $name) {
            if ($forceNewTimestamp || !array_key_exists(self::SECTION_KEY, $sectionsData[$name])) {
                $sectionsData[$name][self::SECTION_KEY] = $markId;
            }
        }
        return $sectionsData;
    }
}
