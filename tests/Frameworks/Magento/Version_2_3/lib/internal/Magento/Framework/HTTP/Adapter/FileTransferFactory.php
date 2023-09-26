<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\HTTP\Adapter;

class FileTransferFactory
{
    /**
     * Create HTTP adapter
     *
     * @param array $options
     * @return \Zend_File_Transfer_Adapter_Http
     */
    public function create(array $options = [])
    {
        return new \Zend_File_Transfer_Adapter_Http($options);
    }
}
