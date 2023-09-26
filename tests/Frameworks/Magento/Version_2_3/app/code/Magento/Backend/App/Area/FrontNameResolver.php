<?php
/**
 * Backend area front name resolver. Reads front name from configuration
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\App\Area;

use Magento\Backend\Setup\ConfigOptionsList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Zend\Uri\Uri;

/**
 * Class to get area front name.
 *
 * @api
 * @since 100.0.2
 */
class FrontNameResolver implements \Magento\Framework\App\Area\FrontNameResolverInterface
{
    const XML_PATH_USE_CUSTOM_ADMIN_PATH = 'admin/url/use_custom_path';

    const XML_PATH_CUSTOM_ADMIN_PATH = 'admin/url/custom_path';

    const XML_PATH_USE_CUSTOM_ADMIN_URL = 'admin/url/use_custom';

    const XML_PATH_CUSTOM_ADMIN_URL = 'admin/url/custom';

    /**
     * Backend area code
     */
    const AREA_CODE = 'adminhtml';

    /**
     * @var array
     */
    protected $standardPorts = ['http' => '80', 'https' => '443'];

    /**
     * @var string
     */
    protected $defaultFrontName;

    /**
     * @var \Magento\Backend\App\ConfigInterface
     */
    protected $config;

    /**
     * Deployment configuration
     *
     * @var DeploymentConfig
     */
    protected $deploymentConfig;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Uri
     */
    private $uri;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @param \Magento\Backend\App\Config $config
     * @param DeploymentConfig $deploymentConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param Uri $uri
     * @param RequestInterface $request
     */
    public function __construct(
        \Magento\Backend\App\Config $config,
        DeploymentConfig $deploymentConfig,
        ScopeConfigInterface $scopeConfig,
        Uri $uri = null,
        RequestInterface $request = null
    ) {
        $this->config = $config;
        $this->defaultFrontName = $deploymentConfig->get(ConfigOptionsList::CONFIG_PATH_BACKEND_FRONTNAME);
        $this->scopeConfig = $scopeConfig;
        $this->uri = $uri ?: ObjectManager::getInstance()->get(Uri::class);
        $this->request = $request ?: ObjectManager::getInstance()->get(RequestInterface::class);
    }

    /**
     * Retrieve area front name
     *
     * @param bool $checkHost If true, verify front name is valid for this url (hostname is correct)
     * @return string|bool
     */
    public function getFrontName($checkHost = false)
    {
        if ($checkHost && !$this->isHostBackend()) {
            return false;
        }
        $isCustomPathUsed = (bool)(string)$this->config->getValue(self::XML_PATH_USE_CUSTOM_ADMIN_PATH);
        if ($isCustomPathUsed) {
            return (string)$this->config->getValue(self::XML_PATH_CUSTOM_ADMIN_PATH);
        }
        return $this->defaultFrontName;
    }

    /**
     * Return whether the host from request is the backend host
     *
     * @return bool
     */
    public function isHostBackend()
    {
        if ($this->scopeConfig->getValue(self::XML_PATH_USE_CUSTOM_ADMIN_URL, ScopeInterface::SCOPE_STORE)) {
            $backendUrl = $this->scopeConfig->getValue(self::XML_PATH_CUSTOM_ADMIN_URL, ScopeInterface::SCOPE_STORE);
        } else {
            $backendUrl = $this->scopeConfig->getValue(Store::XML_PATH_UNSECURE_BASE_URL, ScopeInterface::SCOPE_STORE);
        }
        $host = $this->request->getServer('HTTP_HOST', '');
        return stripos($this->getHostWithPort($backendUrl), (string) $host) !== false;
    }

    /**
     * Get host with port
     *
     * @param string $url
     * @return mixed|string
     */
    private function getHostWithPort($url)
    {
        $this->uri->parse($url);
        $scheme = $this->uri->getScheme();
        $host = $this->uri->getHost();
        $port = $this->uri->getPort();

        if (!$port) {
            $port = isset($this->standardPorts[$scheme]) ? $this->standardPorts[$scheme] : null;
        }
        return isset($port) ? $host . ':' . $port : $host;
    }
}
