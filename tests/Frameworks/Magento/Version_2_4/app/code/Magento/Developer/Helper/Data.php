<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Developer\Helper;

/**
 * Developer config data helper
 *
 * @api
 * @since 100.0.2
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * Dev allow ips config path
     */
    public const XML_PATH_DEV_ALLOW_IPS = 'dev/restrict/allow_ips';

    /**
     * Check if the client remote address is allowed developer ip
     *
     * @param string|null $storeId
     * @return bool
     */
    public function isDevAllowed($storeId = null)
    {
        $allow = true;

        $allowedIps = $this->scopeConfig->getValue(
            self::XML_PATH_DEV_ALLOW_IPS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $remoteAddr = $this->_remoteAddress->getRemoteAddress();
        if (!empty($allowedIps) && !empty($remoteAddr)) {
            $allowedIps = preg_split('#\s*,\s*#', $allowedIps, -1, PREG_SPLIT_NO_EMPTY);
            if (array_search($remoteAddr, $allowedIps) === false
                && array_search($this->_httpHeader->getHttpHost(), $allowedIps) === false
            ) {
                $allow = false;
            }
        }

        return $allow;
    }
}
