<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Api;

/**
 * Interface for managing customer attributes metadata.
 * @api
 * @since 100.0.2
 */
interface CustomerMetadataManagementInterface extends MetadataManagementInterface
{
    const ENTITY_TYPE_CUSTOMER = 'customer';
}
