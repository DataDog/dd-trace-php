<?php

declare(strict_types=1);

namespace Application;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'application' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/application[/:action]',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'simple' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/simple[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'simple',
                    ],
                ]
            ],
            'simpleView' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/simple_view[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'view',
                    ],
                ]
            ],
            'error' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/error[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'error',
                    ],
                ]
            ]
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\IndexController::class => InvokableFactory::class,
            Controller\CommonSpecsController::class => InvokableFactory::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions'       => true,
        'doctype'                  => 'HTML5',
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map' => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];
