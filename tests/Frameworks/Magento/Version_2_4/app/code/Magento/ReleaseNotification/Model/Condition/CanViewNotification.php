<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\ReleaseNotification\Model\Condition;

use Magento\ReleaseNotification\Model\ResourceModel\Viewer\Logger;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\View\Layout\Condition\VisibilityConditionInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Dynamic validator for UI release notification, manage UI component visibility.
 * Return true if the logged in user has not seen the notification.
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class CanViewNotification implements VisibilityConditionInterface
{
    /**
     * Unique condition name.
     *
     * @var string
     */
    private static $conditionName = 'can_view_notification';

    /**
     * Prefix for cache
     *
     * @var string
     */
    private static $cachePrefix = 'release-notification-popup-';

    /**
     * @var Logger
     */
    private $viewerLogger;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var CacheInterface
     */
    private $cacheStorage;

    /**
     * CanViewNotification constructor.
     *
     * @param Logger $viewerLogger
     * @param Session $session
     * @param ProductMetadataInterface $productMetadata
     * @param CacheInterface $cacheStorage
     */
    public function __construct(
        Logger $viewerLogger,
        Session $session,
        ProductMetadataInterface $productMetadata,
        CacheInterface $cacheStorage
    ) {
        $this->viewerLogger = $viewerLogger;
        $this->session = $session;
        $this->productMetadata = $productMetadata;
        $this->cacheStorage = $cacheStorage;
    }

    /**
     * @inheritdoc
     */
    public function isVisible(array $arguments)
    {
        $userId = $this->session->getUser()->getId();
        $cacheKey = self::$cachePrefix . $userId;
        $value = $this->cacheStorage->load($cacheKey);

        if ($value === false) {
            $lastViewVersion = $this->viewerLogger->get($userId)->getLastViewVersion();
            $value = ($lastViewVersion) ?
                version_compare($lastViewVersion, $this->productMetadata->getVersion(), '<') : true;
            $this->cacheStorage->save(false, $cacheKey);
        }
        return (bool)$value;
    }

    /**
     * Get condition name
     *
     * @return string
     */
    public function getName()
    {
        return self::$conditionName;
    }
}
