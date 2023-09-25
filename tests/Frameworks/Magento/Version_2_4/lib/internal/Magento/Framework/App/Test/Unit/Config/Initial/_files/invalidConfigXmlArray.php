<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

return [
    'with_notallowed_handle' => [
        '<?xml version="1.0"?><config><notallowe></notallowe></config>',
        [
            "Element 'notallowe': This element is not expected. Expected is one of" .
            " ( default, stores, websites ).\nLine: 1\n"
        ],
    ]
];
