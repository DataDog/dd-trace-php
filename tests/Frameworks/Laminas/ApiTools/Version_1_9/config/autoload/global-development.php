<?php

declare(strict_types=1);

use Laminas\ApiTools\Admin\Model\ModulePathSpec;

return [
    'view_manager'            => [
        'display_exceptions' => true,
    ],
    'api-tools-admin'         => [
        'path_spec' => ModulePathSpec::PSR_4,
    ],
    'api-tools-configuration' => [
        'enable_short_array' => true,
        'class_name_scalars' => true,
    ],
];
