<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

return [
    'config' => [
        'publishers' => [
            'inventory.counter.updated' => [
                'connections' => [
                    'amqp' => [
                        'name' => 'db',
                        'exchange' => 'magento-db'
                    ],
                ]
            ]
        ],
        'consumers' => [
            'inventoryQtyCounter' => [
                'connection' => 'db'
            ]
        ]
    ]
];
