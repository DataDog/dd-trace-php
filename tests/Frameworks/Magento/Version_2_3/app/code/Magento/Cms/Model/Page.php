<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Cms\Model;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Helper\Page as PageHelper;
use Magento\Cms\Model\Page\CustomLayout\CustomLayoutRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Validation\ValidationException;
use Magento\Framework\Validator\HTML\WYSIWYGValidatorInterface;

/**
 * Cms Page Model
 *
 * @api
 * @method Page setStoreId(int $storeId)
 * @method int getStoreId()
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @since 100.0.2
 */
class Page extends AbstractModel implements PageInterface, IdentityInterface
{
    /**
     * Page ID for the 404 page.
     */
    const NOROUTE_PAGE_ID = 'no-route';

    /**#@+
     * Page's Statuses
     */
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;
    /**#@-*/

    /**
     * CMS page cache tag
     */
    const CACHE_TAG = 'cms_p';

    /**
     * @var string
     */
    protected $_cacheTag = self::CACHE_TAG;

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'cms_page';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var CustomLayoutRepository
     */
    private $customLayoutRepository;

    /**
     * @var WYSIWYGValidatorInterface
     */
    private $wysiwygValidator;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource|null $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb|null $resourceCollection
     * @param array $data
     * @param CustomLayoutRepository|null $customLayoutRepository
     * @param WYSIWYGValidatorInterface|null $wysiwygValidator
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = [],
        ?CustomLayoutRepository $customLayoutRepository = null,
        ?WYSIWYGValidatorInterface $wysiwygValidator = null
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        $this->customLayoutRepository = $customLayoutRepository
            ?? ObjectManager::getInstance()->get(CustomLayoutRepository::class);
        $this->wysiwygValidator = $wysiwygValidator
            ?? ObjectManager::getInstance()->get(WYSIWYGValidatorInterface::class);
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Magento\Cms\Model\ResourceModel\Page::class);
    }

    /**
     * Load object data
     *
     * @param int|null $id
     * @param string $field
     * @return $this
     */
    public function load($id, $field = null)
    {
        if ($id === null) {
            return $this->noRoutePage();
        }
        return parent::load($id, $field);
    }

    /**
     * Load No-Route Page
     *
     * @return \Magento\Cms\Model\Page
     */
    public function noRoutePage()
    {
        return $this->load(self::NOROUTE_PAGE_ID, $this->getIdFieldName());
    }

    /**
     * Receive page store ids
     *
     * @return int[]
     */
    public function getStores()
    {
        return $this->hasData('stores') ? $this->getData('stores') : (array)$this->getData('store_id');
    }

    /**
     * Check if page identifier exist for specific store return page id if page exists
     *
     * @param string $identifier
     * @param int $storeId
     * @return int
     */
    public function checkIdentifier($identifier, $storeId)
    {
        return $this->_getResource()->checkIdentifier($identifier, $storeId);
    }

    /**
     * Prepare page's statuses, available event cms_page_get_available_statuses to customize statuses.
     *
     * @return array
     */
    public function getAvailableStatuses()
    {
        return [self::STATUS_ENABLED => __('Enabled'), self::STATUS_DISABLED => __('Disabled')];
    }

    /**
     * Get identities
     *
     * @return array
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * Get ID
     *
     * @return int
     */
    public function getId()
    {
        return parent::getData(self::PAGE_ID);
    }

    /**
     * Get identifier
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->getData(self::IDENTIFIER);
    }

    /**
     * Get title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->getData(self::TITLE);
    }

    /**
     * Get page layout
     *
     * @return string
     */
    public function getPageLayout()
    {
        return $this->getData(self::PAGE_LAYOUT);
    }

    /**
     * Get meta title
     *
     * @return string|null
     * @since 101.0.0
     */
    public function getMetaTitle()
    {
        return $this->getData(self::META_TITLE);
    }

    /**
     * Get meta keywords
     *
     * @return string
     */
    public function getMetaKeywords()
    {
        return $this->getData(self::META_KEYWORDS);
    }

    /**
     * Get meta description
     *
     * @return string
     */
    public function getMetaDescription()
    {
        return $this->getData(self::META_DESCRIPTION);
    }

    /**
     * Get content heading
     *
     * @return string
     */
    public function getContentHeading()
    {
        return $this->getData(self::CONTENT_HEADING);
    }

    /**
     * Get content
     *
     * @return string
     */
    public function getContent()
    {
        return $this->getData(self::CONTENT);
    }

    /**
     * Get creation time
     *
     * @return string
     */
    public function getCreationTime()
    {
        return $this->getData(self::CREATION_TIME);
    }

    /**
     * Get update time
     *
     * @return string
     */
    public function getUpdateTime()
    {
        return $this->getData(self::UPDATE_TIME);
    }

    /**
     * Get sort order
     *
     * @return string
     */
    public function getSortOrder()
    {
        return $this->getData(self::SORT_ORDER);
    }

    /**
     * Get layout update xml
     *
     * @return string
     */
    public function getLayoutUpdateXml()
    {
        return $this->getData(self::LAYOUT_UPDATE_XML);
    }

    /**
     * Get custom theme
     *
     * @return string
     */
    public function getCustomTheme()
    {
        return $this->getData(self::CUSTOM_THEME);
    }

    /**
     * Get custom root template
     *
     * @return string
     */
    public function getCustomRootTemplate()
    {
        return $this->getData(self::CUSTOM_ROOT_TEMPLATE);
    }

    /**
     * Get custom layout update xml
     *
     * @return string
     */
    public function getCustomLayoutUpdateXml()
    {
        return $this->getData(self::CUSTOM_LAYOUT_UPDATE_XML);
    }

    /**
     * Get custom theme from
     *
     * @return string
     */
    public function getCustomThemeFrom()
    {
        return $this->getData(self::CUSTOM_THEME_FROM);
    }

    /**
     * Get custom theme to
     *
     * @return string
     */
    public function getCustomThemeTo()
    {
        return $this->getData(self::CUSTOM_THEME_TO);
    }

    /**
     * Is active
     *
     * @return bool
     */
    public function isActive()
    {
        return (bool)$this->getData(self::IS_ACTIVE);
    }

    /**
     * Set ID
     *
     * @param int $id
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setId($id)
    {
        return $this->setData(self::PAGE_ID, $id);
    }

    /**
     * Set identifier
     *
     * @param string $identifier
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setIdentifier($identifier)
    {
        return $this->setData(self::IDENTIFIER, $identifier);
    }

    /**
     * Set title
     *
     * @param string $title
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setTitle($title)
    {
        return $this->setData(self::TITLE, $title);
    }

    /**
     * Set page layout
     *
     * @param string $pageLayout
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setPageLayout($pageLayout)
    {
        return $this->setData(self::PAGE_LAYOUT, $pageLayout);
    }

    /**
     * Set meta title
     *
     * @param string $metaTitle
     * @return \Magento\Cms\Api\Data\PageInterface
     * @since 101.0.0
     */
    public function setMetaTitle($metaTitle)
    {
        return $this->setData(self::META_TITLE, $metaTitle);
    }

    /**
     * Set meta keywords
     *
     * @param string $metaKeywords
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setMetaKeywords($metaKeywords)
    {
        return $this->setData(self::META_KEYWORDS, $metaKeywords);
    }

    /**
     * Set meta description
     *
     * @param string $metaDescription
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setMetaDescription($metaDescription)
    {
        return $this->setData(self::META_DESCRIPTION, $metaDescription);
    }

    /**
     * Set content heading
     *
     * @param string $contentHeading
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setContentHeading($contentHeading)
    {
        return $this->setData(self::CONTENT_HEADING, $contentHeading);
    }

    /**
     * Set content
     *
     * @param string $content
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setContent($content)
    {
        return $this->setData(self::CONTENT, $content);
    }

    /**
     * Set creation time
     *
     * @param string $creationTime
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCreationTime($creationTime)
    {
        return $this->setData(self::CREATION_TIME, $creationTime);
    }

    /**
     * Set update time
     *
     * @param string $updateTime
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setUpdateTime($updateTime)
    {
        return $this->setData(self::UPDATE_TIME, $updateTime);
    }

    /**
     * Set sort order
     *
     * @param string $sortOrder
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setSortOrder($sortOrder)
    {
        return $this->setData(self::SORT_ORDER, $sortOrder);
    }

    /**
     * Set layout update xml
     *
     * @param string $layoutUpdateXml
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setLayoutUpdateXml($layoutUpdateXml)
    {
        return $this->setData(self::LAYOUT_UPDATE_XML, $layoutUpdateXml);
    }

    /**
     * Set custom theme
     *
     * @param string $customTheme
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCustomTheme($customTheme)
    {
        return $this->setData(self::CUSTOM_THEME, $customTheme);
    }

    /**
     * Set custom root template
     *
     * @param string $customRootTemplate
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCustomRootTemplate($customRootTemplate)
    {
        return $this->setData(self::CUSTOM_ROOT_TEMPLATE, $customRootTemplate);
    }

    /**
     * Set custom layout update xml
     *
     * @param string $customLayoutUpdateXml
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCustomLayoutUpdateXml($customLayoutUpdateXml)
    {
        return $this->setData(self::CUSTOM_LAYOUT_UPDATE_XML, $customLayoutUpdateXml);
    }

    /**
     * Set custom theme from
     *
     * @param string $customThemeFrom
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCustomThemeFrom($customThemeFrom)
    {
        return $this->setData(self::CUSTOM_THEME_FROM, $customThemeFrom);
    }

    /**
     * Set custom theme to
     *
     * @param string $customThemeTo
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setCustomThemeTo($customThemeTo)
    {
        return $this->setData(self::CUSTOM_THEME_TO, $customThemeTo);
    }

    /**
     * Set is active
     *
     * @param int|bool $isActive
     * @return \Magento\Cms\Api\Data\PageInterface
     */
    public function setIsActive($isActive)
    {
        return $this->setData(self::IS_ACTIVE, $isActive);
    }

    /**
     * Validate identifier before saving the entity.
     *
     * @return void
     * @throws LocalizedException
     */
    private function validateNewIdentifier(): void
    {
        $originalIdentifier = $this->getOrigData('identifier');
        $currentIdentifier = $this->getIdentifier();
        if ($this->getId() && $originalIdentifier !== $currentIdentifier) {
            switch ($originalIdentifier) {
                case $this->getScopeConfig()->getValue(PageHelper::XML_PATH_NO_ROUTE_PAGE):
                    throw new LocalizedException(
                        __('This identifier is reserved for "CMS No Route Page" in configuration.')
                    );
                case $this->getScopeConfig()->getValue(PageHelper::XML_PATH_HOME_PAGE):
                    throw new LocalizedException(
                        __('This identifier is reserved for "CMS Home Page" in configuration.')
                    );
                case $this->getScopeConfig()->getValue(PageHelper::XML_PATH_NO_COOKIES_PAGE):
                    throw new LocalizedException(
                        __('This identifier is reserved for "CMS No Cookies Page" in configuration.')
                    );
            }
        }
    }

    /**
     * @inheritdoc
     * @since 101.0.0
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function beforeSave()
    {
        if ($this->hasDataChanges()) {
            $this->setUpdateTime(null);
        }

        $this->validateNewIdentifier();

        //Removing deprecated custom layout update if a new value is provided
        $layoutUpdate = $this->getData('layout_update_selected');
        if ($layoutUpdate === '_no_update_' || ($layoutUpdate && $layoutUpdate !== '_existing_')) {
            $this->setCustomLayoutUpdateXml(null);
            $this->setLayoutUpdateXml(null);
        }
        if ($layoutUpdate === '_no_update_' || $layoutUpdate === '_existing_') {
            $layoutUpdate = null;
        }
        $this->setData('layout_update_selected', $layoutUpdate);
        $this->customLayoutRepository->validateLayoutSelectedFor($this);

        //Validating Content HTML.
        $oldValue = null;
        if ($this->getId()) {
            if ($this->getOrigData()) {
                $oldValue = $this->getOrigData(self::CONTENT);
            } elseif (array_key_exists(self::CONTENT, $this->getStoredData())) {
                $oldValue = $this->getStoredData()[self::CONTENT];
            }
        }
        if ($this->getContent() && $this->getContent() !== $oldValue) {
            try {
                $this->wysiwygValidator->validate($this->getContent());
            } catch (ValidationException $exception) {
                throw new ValidationException(
                    __('Content HTML contains restricted elements. %1', $exception->getMessage()),
                    $exception
                );
            }
        }

        return parent::beforeSave();
    }

    /**
     * Returns scope config.
     *
     * @return ScopeConfigInterface
     */
    private function getScopeConfig()
    {
        if (null === $this->scopeConfig) {
            $this->scopeConfig = \Magento\Framework\App\ObjectManager::getInstance()->get(ScopeConfigInterface::class);
        }

        return $this->scopeConfig;
    }
}
