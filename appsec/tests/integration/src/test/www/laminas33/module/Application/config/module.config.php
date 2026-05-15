<?php

declare(strict_types=1);

namespace Application;

use Application\Controller\DynamicPathController;
use Application\Controller\LoginControllerFactory;
use Laminas\Router\Http\Hostname;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Method;
use Laminas\Router\Http\Placeholder;
use Laminas\Router\Http\Regex;
use Laminas\Router\Http\Scheme;
use Laminas\Router\Http\Segment;
use Laminas\Router\Http\Wildcard;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/',
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'application' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/application[/:action]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                    ],
                    'defaults' => [
                        'controller' => Controller\IndexController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'authenticate' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/authenticate',
                    'defaults' => [
                        'controller' => Controller\LoginController::class,
                        'action' => 'auth',
                    ],
                ],
            ],
            'behind_auth' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/behind-auth',
                    'defaults' => [
                        'controller' => Controller\LoginController::class,
                        'action' => 'behindAuth',
                    ],
                ],
            ],
            'dynamic_path' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/dynamic-path[/:param01]',
                    'constraints' => [
                        'param01' => '[a-zA-Z0-9_-]+',
                    ],
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'nested_resource' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/resource',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'item' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:resourceId',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'sub' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:subId',
                                    'defaults' => [
                                        'controller' => DynamicPathController::class,
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'verb_test' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/verb-test',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'get'    => ['type' => Method::class, 'options' => ['verb' => 'GET']],
                    'post'   => ['type' => Method::class, 'options' => ['verb' => 'POST']],
                    'put'    => ['type' => Method::class, 'options' => ['verb' => 'PUT']],
                    'patch'  => ['type' => Method::class, 'options' => ['verb' => 'PATCH']],
                    'delete' => ['type' => Method::class, 'options' => ['verb' => 'DELETE']],
                ],
            ],
            'multi_verb' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/multi-verb',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'read' => [
                        'type' => Method::class,
                        'options' => ['verb' => 'GET,HEAD,OPTIONS'],
                    ],
                    'write' => [
                        'type' => Method::class,
                        'options' => ['verb' => 'POST,PUT'],
                    ],
                ],
            ],
            'chained_resource' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/chain',
                ],
                'chain_routes' => [
                    [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:chainId',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'regex_year' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/regex-year/(?P<year>\d{4})',
                    'spec' => '/regex-year/%year%',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                        'year' => '2000',
                    ],
                ],
            ],
            'scheme_http_gate' => [
                'type' => Scheme::class,
                'options' => [
                    'scheme' => 'http',
                    'defaults' => [],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'page' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/scheme-only-page',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'placeholder_branch' => [
                'type' => Placeholder::class,
                'options' => [
                    'defaults' => [],
                ],
                'child_routes' => [
                    'under' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/placeholder-literal',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'any_verb' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/any-verb',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'wildcard_keys' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/wildcard-keys',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'pairs' => [
                        'type' => Wildcard::class,
                        'options' => [
                            'defaults' => [],
                        ],
                    ],
                ],
            ],
            'tenant_with_profile' => [
                'type' => Hostname::class,
                'options' => [
                    'route' => ':tenant.example.com',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'profile' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/profile',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\LoginController::class => LoginControllerFactory::class,
            Controller\IndexController::class => InvokableFactory::class,
            DynamicPathController::class => InvokableFactory::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => true,
        'display_exceptions' => true,
        'doctype' => 'HTML5',
        'not_found_template' => 'error/404',
        'exception_template' => 'error/index',
        'template_map' => [
            'layout/layout' => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/404' => __DIR__ . '/../view/error/404.phtml',
            'error/index' => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
];
