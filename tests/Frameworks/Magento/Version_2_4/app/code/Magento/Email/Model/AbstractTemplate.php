<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Email\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\TemplateTypesInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Information as StoreInformation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\MediaStorage\Helper\File\Storage\Database;

/**
 * Template model class.
 *
 * phpcs:disable Magento2.Classes.AbstractApi
 * @author      Magento Core Team <core@magentocommerce.com>
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @api
 * @since 100.0.2
 */
abstract class AbstractTemplate extends AbstractModel implements TemplateTypesInterface
{
    /**
     * Default design area for emulation
     */
    public const DEFAULT_DESIGN_AREA = 'frontend';

    /**
     * Default path to email logo
     */
    public const DEFAULT_LOGO_FILE_ID = 'Magento_Email::logo_email.png';

    /**
     * Email logo url
     */
    public const XML_PATH_DESIGN_EMAIL_LOGO = 'design/email/logo';

    /**
     * Email logo alt text
     */
    public const XML_PATH_DESIGN_EMAIL_LOGO_ALT = 'design/email/logo_alt';

    /**
     * Email logo width
     */
    public const XML_PATH_DESIGN_EMAIL_LOGO_WIDTH = 'design/email/logo_width';

    /**
     * Email logo height
     */
    public const XML_PATH_DESIGN_EMAIL_LOGO_HEIGHT = 'design/email/logo_height';

    /**
     * Configuration of design package for template
     *
     * @var DataObject
     */
    private $designConfig;

    /**
     * Whether template is child of another template
     *
     * @var bool
     */
    private $isChildTemplate = false;

    /**
     * Email template filter
     *
     * @var \Magento\Email\Model\Template\Filter
     */
    private $templateFilter;

    /**
     * Configuration of emulated design package.
     *
     * @var DataObject|boolean
     */
    private $emulatedDesignConfig = false;

    /**
     * Package area
     *
     * @var string
     */
    private $area;

    /**
     * Store id
     *
     * @var int
     */
    private $store;

    /**
     * Tracks whether design has been applied within the context of this template model.
     *
     * Important as there are multiple entry points for the applyDesignConfig method.
     *
     * @var bool
     */
    private $hasDesignBeenApplied = false;

    /**
     * @var \Magento\Email\Model\TemplateFactory
     */
    protected $templateFactory = null;

    /**
     * Design package instance
     *
     * @var \Magento\Framework\View\DesignInterface
     */
    protected $design = null;

    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    protected $appEmulation;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * Asset service
     *
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $assetRepo;

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $filesystem;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Email\Model\Template\Config
     */
    protected $emailConfig;

    /**
     * @var \Magento\Framework\Filter\FilterManager
     */
    protected $filterManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlModel;

    /**
     * @var Database
     */
    private $fileStorageDatabase;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\View\DesignInterface $design
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Store\Model\App\Emulation $appEmulation
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\View\Asset\Repository $assetRepo
     * @param \Magento\Framework\Filesystem $filesystem
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Email\Model\Template\Config $emailConfig
     * @param \Magento\Email\Model\TemplateFactory $templateFactory
     * @param \Magento\Framework\Filter\FilterManager $filterManager
     * @param \Magento\Framework\UrlInterface $urlModel
     * @param array $data
     * @param Database $fileStorageDatabase
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\View\DesignInterface $design,
        \Magento\Framework\Registry $registry,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Email\Model\Template\Config $emailConfig,
        \Magento\Email\Model\TemplateFactory $templateFactory,
        \Magento\Framework\Filter\FilterManager $filterManager,
        \Magento\Framework\UrlInterface $urlModel,
        array $data = [],
        Database $fileStorageDatabase = null
    ) {
        $this->design = $design;
        $this->area = isset($data['area']) ? $data['area'] : null;
        $this->store = isset($data['store']) ? $data['store'] : null;
        $this->appEmulation = $appEmulation;
        $this->storeManager = $storeManager;
        $this->assetRepo = $assetRepo;
        $this->filesystem = $filesystem;
        $this->scopeConfig = $scopeConfig;
        $this->emailConfig = $emailConfig;
        $this->templateFactory = $templateFactory;
        $this->filterManager = $filterManager;
        $this->urlModel = $urlModel;
        $this->fileStorageDatabase = $fileStorageDatabase ?:
            \Magento\Framework\App\ObjectManager::getInstance()->get(Database::class);
        parent::__construct($context, $registry, null, null, $data);
    }

    /**
     * Get contents of the included template for template directive
     *
     * @param string $configPath
     * @param array $variables
     * @return string
     */
    public function getTemplateContent($configPath, array $variables)
    {
        $template = $this->getTemplateInstance();

        // Ensure child templates have the same area/store context as parent
        $template->setDesignConfig($this->getDesignConfig()->toArray())
            ->loadByConfigPath($configPath, $variables)
            ->setTemplateType($this->getType())
            ->setIsChildTemplate(true);

        // automatically strip tags if in a plain-text parent
        if ($this->isPlain()) {
            $templateText = $this->filterManager->stripTags($template->getTemplateText());
            $template->setTemplateText(trim($templateText));
        }

        $processedTemplate = $template->getProcessedTemplate($variables);
        if ($this->isPlain()) {
            $processedTemplate = trim($processedTemplate);
        }

        return $processedTemplate;
    }

    /**
     * Return a new instance of the template object. Used by the template directive.
     *
     * @return \Magento\Email\Model\AbstractTemplate
     */
    protected function getTemplateInstance()
    {
        return $this->templateFactory->create();
    }

    /**
     * Load template from database when overridden in configuration or load default from relevant file system location.
     *
     * @param string $configPath
     * @return \Magento\Email\Model\AbstractTemplate
     */
    public function loadByConfigPath($configPath)
    {
        $store = $this->getDesignConfig()->getStore();
        $templateId = $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $store);

        if (is_numeric($templateId)) {
            $this->load($templateId);
        } else {
            $this->loadDefault($templateId);
        }
        return $this;
    }

    /**
     * Load default email template
     *
     * @param string $templateId
     * @return $this
     */
    public function loadDefault($templateId)
    {
        $designParams = $this->getDesignParams();
        $templateFile = $this->emailConfig->getTemplateFilename($templateId, $designParams);
        $templateType = $this->emailConfig->getTemplateType($templateId);
        $templateTypeCode = $templateType == 'html' ? self::TYPE_HTML : self::TYPE_TEXT;
        $this->setTemplateType($templateTypeCode);

        $rootDirectory = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $templateText = $rootDirectory->readFile($rootDirectory->getRelativePath($templateFile));

        /**
         * trim copyright message
         */
        if (preg_match('/^<!--[\w\W]+?-->/m', $templateText, $matches) && strpos($matches[0], 'Copyright') !== false) {
            $templateText = str_replace($matches[0], '', $templateText);
        }

        if (preg_match('/<!--@subject\s*(.*?)\s*@-->/u', $templateText, $matches)) {
            $this->setTemplateSubject($matches[1]);
            $templateText = str_replace($matches[0], '', $templateText);
        }

        if (preg_match('/<!--@vars\s*((?:.)*?)\s*@-->/us', $templateText, $matches)) {
            $this->setData('orig_template_variables', str_replace("\n", '', $matches[1]));
            $templateText = str_replace($matches[0], '', $templateText);
        }

        if (preg_match('/<!--@styles\s*(.*?)\s*@-->/s', $templateText, $matches)) {
            $this->setTemplateStyles($matches[1]);
            $templateText = str_replace($matches[0], '', $templateText);
        }

        // Remove comment lines and extra spaces
        $templateText = trim(preg_replace('#\{\*.*\*\}#suU', '', $templateText));

        $this->setTemplateText($templateText);
        $this->setId($templateId);

        return $this;
    }

    /**
     * Process email template code
     *
     * @param array $variables
     * @return string
     * @throws \Magento\Framework\Exception\MailException
     */
    public function getProcessedTemplate(array $variables = [])
    {
        $processor = $this->getTemplateFilter()
            ->setPlainTemplateMode($this->isPlain())
            ->setIsChildTemplate($this->isChildTemplate())
            ->setTemplateProcessor([$this, 'getTemplateContent']);

        $variables['this'] = $this;

        $isDesignApplied = $this->applyDesignConfig();

        // Set design params so that CSS will be loaded from the proper theme
        $processor->setDesignParams($this->getDesignParams());

        if (isset($variables['subscriber'])) {
            $storeId = $variables['subscriber']->getStoreId();
        } else {
            $storeId = $this->getDesignConfig()->getStore();
        }
        $processor->setStoreId($storeId);

        // Populate the variables array with store, store info, logo, etc. variables
        $variables = $this->addEmailVariables($variables, $storeId);
        $processor->setVariables($variables);

        try {
            $result = $processor->filter($this->getTemplateText());
        } catch (\Exception $e) {
            $this->cancelDesignConfig();
            throw new \LogicException(__($e->getMessage()), $e->getCode(), $e);
        }

        if ($isDesignApplied) {
            $this->cancelDesignConfig();
        }
        return $result;
    }

    /**
     * Get default email logo image
     *
     * @return string
     */
    public function getDefaultEmailLogo()
    {
        $designParams = $this->getDesignParams();
        return $this->assetRepo->getUrlWithParams(
            self::DEFAULT_LOGO_FILE_ID,
            $designParams
        );
    }

    /**
     * Return logo URL for emails. Take logo from theme if custom logo is undefined
     *
     * @param  Store|int|string $store
     * @return string
     */
    protected function getLogoUrl($store)
    {
        $store = $this->storeManager->getStore($store);
        $fileName = $this->scopeConfig->getValue(
            self::XML_PATH_DESIGN_EMAIL_LOGO,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        if ($fileName) {
            $uploadDir = \Magento\Email\Model\Design\Backend\Logo::UPLOAD_DIR;
            $mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
            if ($this->fileStorageDatabase->checkDbUsage() &&
                !$mediaDirectory->isFile($uploadDir . '/' . $fileName)
            ) {
                $this->fileStorageDatabase->saveFileToFilesystem($uploadDir . '/' . $fileName);
            }
            if ($mediaDirectory->isFile($uploadDir . '/' . $fileName)) {
                return $this->storeManager->getStore()->getBaseUrl(
                    \Magento\Framework\UrlInterface::URL_TYPE_MEDIA
                ) . $uploadDir . '/' . $fileName;
            }
        }
        return $this->getDefaultEmailLogo();
    }

    /**
     * Return logo alt for emails
     *
     * @param  Store|int|string $store
     * @return string
     */
    protected function getLogoAlt($store)
    {
        $store = $this->storeManager->getStore($store);
        $alt = $this->scopeConfig->getValue(
            self::XML_PATH_DESIGN_EMAIL_LOGO_ALT,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        if ($alt) {
            return $alt;
        }
        return $store->getFrontendName();
    }

    /**
     * Add variables that are used by transactional and newsletter emails
     *
     * @param array $variables
     * @param null|string|bool|int|Store $storeId
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function addEmailVariables($variables, $storeId)
    {
        $store = $this->storeManager->getStore($storeId);
        if (!isset($variables['store'])) {
            $variables['store'] = $store;
        }
        $storeAddress = $variables['store']->getFormattedAddress();
        $variables['store']->setData('formatted_address', $storeAddress);
        if (!isset($variables['store']['frontend_name'])) {
            $variables['store']['frontend_name'] = $store->getFrontendName();
        }
        if (!isset($variables['logo_url'])) {
            $variables['logo_url'] = $this->getLogoUrl($storeId);
        }
        if (!isset($variables['logo_alt'])) {
            $variables['logo_alt'] = $this->getLogoAlt($storeId);
        }
        if (!isset($variables['logo_width'])) {
            $variables['logo_width'] = $this->scopeConfig->getValue(
                self::XML_PATH_DESIGN_EMAIL_LOGO_WIDTH,
                ScopeInterface::SCOPE_STORE,
                $store
            );
        }
        if (!isset($variables['logo_height'])) {
            $variables['logo_height'] = $this->scopeConfig->getValue(
                self::XML_PATH_DESIGN_EMAIL_LOGO_HEIGHT,
                ScopeInterface::SCOPE_STORE,
                $store
            );
        }
        if (!isset($variables['store_phone'])) {
            $variables['store_phone'] = $this->scopeConfig->getValue(
                StoreInformation::XML_PATH_STORE_INFO_PHONE,
                ScopeInterface::SCOPE_STORE,
                $store
            );
        }
        if (!isset($variables['store_hours'])) {
            $variables['store_hours'] = $this->scopeConfig->getValue(
                StoreInformation::XML_PATH_STORE_INFO_HOURS,
                ScopeInterface::SCOPE_STORE,
                $store
            );
        }
        if (!isset($variables['store_email'])) {
            $variables['store_email'] = $this->scopeConfig->getValue(
                'trans_email/ident_support/email',
                ScopeInterface::SCOPE_STORE,
                $store
            );
        }
        // If template is text mode, don't include styles
        if (!$this->isPlain() && !isset($variables['template_styles'])) {
            $variables['template_styles'] = $this->getTemplateStyles();
        }

        return $variables;
    }

    /**
     * Apply design config so that emails are processed within the context of the appropriate area/store/theme.
     *
     * @return bool
     */
    protected function applyDesignConfig()
    {
        // Only run app emulation if this is the parent template and emulation isn't already running.
        // Otherwise child will run inside parent emulation.
        if ($this->isChildTemplate() || $this->hasDesignBeenApplied) {
            return false;
        }
        $this->hasDesignBeenApplied = true;

        $designConfig = $this->getDesignConfig();
        $storeId = $designConfig->getStore();
        $area = $designConfig->getArea();
        if ($storeId !== null) {
            // Force emulation in case email is being sent from same store so that theme will be loaded. Helpful
            // for situations where emails may be sent from bootstrap files that load frontend store, but not theme
            $this->appEmulation->startEnvironmentEmulation($storeId, $area, true);
        }
        return true;
    }

    /**
     * Revert design settings to previous
     *
     * @return $this
     */
    protected function cancelDesignConfig()
    {
        $this->appEmulation->stopEnvironmentEmulation();
        $this->urlModel->setScope(null);
        $this->hasDesignBeenApplied = false;

        return $this;
    }

    /**
     * Store the area associated with a template so that it will be returned by getDesignConfig and getDesignParams
     *
     * @param string $templateId
     * @return $this
     */
    public function setForcedArea($templateId)
    {
        if ($this->area === null) {
            $this->area = $this->emailConfig->getTemplateArea($templateId);
        }

        return $this;
    }

    /**
     * Manually set a theme that will be used by getParams
     *
     * Used to force the loading of an email template from a specific theme
     *
     * @param string $templateId
     * @param string $theme
     * @return $this
     */
    public function setForcedTheme($templateId, $theme)
    {
        $area = $this->emailConfig->getTemplateArea($templateId);
        $this->design->setDesignTheme($theme, $area);
        return $this;
    }

    /**
     * Returns the design params for the template being processed
     *
     * @return array
     */
    public function getDesignParams()
    {
        return [
            // Retrieve area from getDesignConfig, rather than the getDesignTheme->getArea(), as the latter doesn't
            // return the emulated area
            'area' => $this->getDesignConfig()->getArea(),
            'theme' => $this->design->getDesignTheme()->getCode(),
            'themeModel' => $this->design->getDesignTheme(),
            'locale' => $this->design->getLocale(),
        ];
    }

    /**
     * Get design configuration data
     *
     * @return DataObject
     */
    public function getDesignConfig()
    {
        if ($this->designConfig === null) {
            if ($this->area === null) {
                $this->area = $this->design->getArea();
            }
            if ($this->store === null) {
                $this->store = $this->storeManager->getStore()->getId();
            }
            $this->designConfig = new DataObject(
                ['area' => $this->area, 'store' => $this->store]
            );
        }
        return $this->designConfig;
    }

    /**
     * Initialize design information for template processing
     *
     * @param array $config
     * @return $this
     * @throws LocalizedException
     */
    public function setDesignConfig(array $config)
    {
        if (!isset($config['area']) || !isset($config['store'])) {
            throw new LocalizedException(
                __('The design config needs an area and a store. Verify that both are set and try again.')
            );
        }
        $this->getDesignConfig()->setData($config);
        return $this;
    }

    /**
     * Check whether template is child of another template
     *
     * @return bool
     */
    public function isChildTemplate()
    {
        return $this->isChildTemplate;
    }

    /**
     * Set whether template is child of another template
     *
     * @param bool $isChildTemplate
     * @return $this
     */
    public function setIsChildTemplate($isChildTemplate)
    {
        $this->isChildTemplate = (bool) $isChildTemplate;
        return $this;
    }

    /**
     * Declare template processing filter
     *
     * @param \Magento\Email\Model\Template\Filter $filter
     * @return $this
     */
    public function setTemplateFilter(Template\Filter $filter)
    {
        $this->templateFilter = $filter;
        return $this;
    }

    /**
     * Get filter object for template processing
     *
     * @return \Magento\Email\Model\Template\Filter
     */
    public function getTemplateFilter()
    {
        if (empty($this->templateFilter)) {
            $this->templateFilter = $this->getFilterFactory()->create();
            $this->templateFilter->setUseAbsoluteLinks($this->getUseAbsoluteLinks())
                ->setStoreId($this->getDesignConfig()->getStore())
                ->setUrlModel($this->urlModel);
        }
        return $this->templateFilter;
    }

    /**
     * Save current design config and replace with design config from specified store. Event is not dispatched.
     *
     * @param null|bool|int|string $storeId
     * @param string $area
     * @return void
     */
    public function emulateDesign($storeId, $area = self::DEFAULT_DESIGN_AREA)
    {
        if ($storeId !== null && $storeId !== false) {
            // save current design settings
            $this->emulatedDesignConfig = clone $this->getDesignConfig();
            if ($this->getDesignConfig()->getStore() != $storeId
                || $this->getDesignConfig()->getArea() != $area
            ) {
                $this->setDesignConfig(['area' => $area, 'store' => $storeId]);
                $this->applyDesignConfig();
            }
        } else {
            $this->emulatedDesignConfig = false;
        }
    }

    /**
     * Revert to last design config, used before emulation
     *
     * @return void
     */
    public function revertDesign()
    {
        if ($this->emulatedDesignConfig) {
            $this->setDesignConfig($this->emulatedDesignConfig->getData());
            $this->cancelDesignConfig();
            $this->emulatedDesignConfig = false;
        }
    }

    /**
     * Return true if template type eq text
     *
     * @return boolean
     */
    public function isPlain()
    {
        return $this->getType() == self::TYPE_TEXT;
    }

    /**
     * Getter for filter factory that is specific to the type of template being processed
     *
     * @return mixed
     */
    abstract protected function getFilterFactory();

    /**
     * Getter for template type
     *
     * @return int|string
     */
    abstract public function getType();

    /**
     * Generate URL for the specified store.
     *
     * @param Store $store
     * @param string $route
     * @param array $params
     * @return string
     */
    public function getUrl(Store $store, $route = '', $params = [])
    {
        $url = $this->urlModel->setScope($store);
        if ($this->storeManager->getStore()->getId() != $store->getId()) {
            $params['_scope_to_url'] = true;
        }
        return $url->getUrl($route, $params);
    }
}
