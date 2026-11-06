<?php

declare(strict_types=1);

namespace Application;

use Application\Controller\DynamicPathController;
use Application\Controller\LoginControllerFactory;
use Application\Router\DelegatingRoute;
use Application\Router\PreviewSegment;
use Application\Router\SwappableRoute;
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
        // Routes that reference a prototype by name share its instance.
        'prototypes' => [
            'shared_format' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '[.:format]',
                    'constraints' => [
                        'format' => 'json|xml',
                    ],
                ],
            ],
            'shared_lang' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '[/:lang]',
                    'constraints' => [
                        'lang' => '[a-z]{2}',
                    ],
                ],
            ],
        ],
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
            'simple' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/simple[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'simple',
                    ],
                ],
            ],
            'simpleView' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/simple_view[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'view',
                    ],
                ],
            ],
            'error' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/error[/:key][/:pwd]',
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'error',
                    ],
                ],
            ],
            'login_auth' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/login/auth',
                    'defaults' => [
                        'controller' => Controller\LoginController::class,
                        'action' => 'auth',
                    ],
                ],
            ],
            'login_signup' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/login/signup',
                    'defaults' => [
                        'controller' => Controller\LoginController::class,
                        'action' => 'signup',
                    ],
                ],
            ],
            'behind_auth' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/behind_auth',
                    'defaults' => [
                        'controller' => Controller\LoginController::class,
                        'action' => 'behindAuth',
                    ],
                ],
            ],
            'dynamic_route' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/dynamic_route[/:param01[/static[/:param02]]]',
                    'constraints' => [
                        'param01' => '[a-zA-Z0-9_-]+',
                        'param02' => '[a-zA-Z0-9_-]+',
                    ],
                    'defaults' => [
                        'controller' => Controller\CommonSpecsController::class,
                        'action' => 'dynamicRoute',
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
            'behind_auth_hyphen' => [
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
            'regex_optional_format' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-regex/(?P<id>[a-z]+)(?:\.(?P<format>[a-z]+))?',
                    'spec' => '/normalized-regex/%id%.%format%',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                        'format' => 'html',
                    ],
                ],
            ],
            'regex_ambiguous_default' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-regex-ambiguous/' .
                        '(?P<name>.+)(?:\.(?P<ext>pdf|json))?',
                    'spec' => '/normalized-regex-ambiguous/%name%.%ext%',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'ambiguous',
                        'ext' => 'html',
                    ],
                ],
            ],
            'normalized_encoded_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-encoded[/:slug]',
                    'constraints' => [
                        'slug' => '.+',
                    ],
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_static_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-static[/draft]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_static_prefix_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-static-prefix[/normalized]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_dynamic_prefix_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-dynamic-prefix[/:value]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                        'value' => 'normalized-dynamic-prefix',
                    ],
                ],
            ],
            'normalized_encoded_cache_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-encoded-cache[/:slug]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_encoded_lowercase_optional' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-encoded-lowercase[/:slug]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_hyphenated_name' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-name/:user-id',
                    'constraints' => [
                        'user-id' => '[a-z]+',
                    ],
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_wildcard_collision' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-wildcard/:param1',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'tail' => [
                        'type' => Wildcard::class,
                        'options' => [
                            'defaults' => [],
                        ],
                    ],
                ],
            ],
            'normalized_nested_optional' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-nested',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'item' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/:slug[/:page]',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // shared_format is matched at two positions of the same route.
            'normalized_proto_format' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-proto',
                ],
                'chain_routes' => ['shared_format'],
                'may_terminate' => false,
                'child_routes' => [
                    'users' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/users',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                        'chain_routes' => ['shared_format'],
                    ],
                ],
            ],
            // Child routes are tried last-defined first, so for
            // /normalized-sibling/en/c2 the failing 'c' child matches
            // shared_lang again (absent, after /c) before 'c2' matches.
            'normalized_proto_sibling' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-sibling',
                ],
                'chain_routes' => ['shared_lang'],
                'may_terminate' => false,
                'child_routes' => [
                    'c2' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/c2',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                    'c' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/c',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                        'chain_routes' => [
                            'shared_lang',
                            [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/zz',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            // Both have the http.route /normalized-collide/:lang[/:x]/y[/:z],
            // but split its optionals differently between base and child.
            // The constraints decide which of them a path matches.
            'normalized_collide_base' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-collide/:lang[/:x]',
                    'constraints' => [
                        'lang' => '[a-z]{2}',
                        'x' => '[0-9]+',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'c' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '/y[/:z]',
                            'constraints' => [
                                'z' => '[0-9]+',
                            ],
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            'normalized_collide_child' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-collide/:lang',
                    'constraints' => [
                        'lang' => '[A-Z]{2}',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'c' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '[/:x]/y[/:z]',
                            'constraints' => [
                                'x' => '[a-z]+',
                                'z' => '[a-z]+',
                            ],
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // Custom route types: one returns the RouteMatch of a Segment it
            // wraps, the other builds a RouteMatch of its own.
            'normalized_custom' => [
                'type' => DelegatingRoute::class,
                'options' => [
                    'route' => '/normalized-custom[/:id]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_preview' => [
                'type' => PreviewSegment::class,
                'options' => [
                    'route' => '/normalized-preview[/:id]',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            // A Segment and a Regex with a parameter of the same name: the
            // Regex's optional format takes part only when it captures it.
            'normalized_mixed' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-mixed[/:format]',
                    'constraints' => [
                        'format' => 'json|xml',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'page' => [
                        'type' => Regex::class,
                        'options' => [
                            'regex' => '/(?<page>[a-z]+)(?:\.(?<format>json|xml))?',
                            'spec' => '/%page%.%format%',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // Two Regexes with an optional capture of the same name.
            'normalized_rx_pair' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-rx-pair/(?<a>[a-z]+)(?:-(?<v>[0-9]+))?',
                    'spec' => '/normalized-rx-pair/%a%-%v%',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'c' => [
                        'type' => Regex::class,
                        'options' => [
                            'regex' => '/(?<b>[a-z]+)(?:-(?<v>[0-9]+))?',
                            'spec' => '/%b%-%v%',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // A Regex spec that continues the URL segment a Segment parameter
            // ends: version and rest share one segment.
            'normalized_joined' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/normalized-joined/v:version',
                    'constraints' => [
                        'version' => '[0-9]+',
                    ],
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'rest' => [
                        'type' => Regex::class,
                        'options' => [
                            'regex' => '(?<rest>[a-z]+)(?:/(?<id>[0-9]+))?',
                            'spec' => '%rest%/%id%',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // Static text that looks like route syntax: a percent-encoded
            // character and a colon.
            'normalized_encoded_static' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-caf%C3%A9',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'item' => [
                        'type' => Regex::class,
                        'options' => [
                            'regex' => '/(?<id>[0-9]+)(?:\.(?<f>[a-z]+))?',
                            'spec' => '/%id%.%f%',
                        ],
                    ],
                ],
            ],
            'normalized_colon_static' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-user:me',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'tab' => [
                        'type' => Segment::class,
                        'options' => [
                            'route' => '[/:tab]',
                        ],
                    ],
                ],
            ],
            // Regex specs whose static text the regex need not match verbatim:
            // a non-capturing optional, and a separator before an optional
            // capture.
            'normalized_rx_archive' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-rx-archive(?:/archive)?/(?<id>[0-9]+)',
                    'spec' => '/normalized-rx-archive/archive/%id%',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            'normalized_rx_dot' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-rx-dot/v1\.(?<x>[a-z]+)?',
                    'spec' => '/normalized-rx-dot/v1.%x%',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            // A route that builds its own RouteMatch after a Literal whose
            // text reads as Segment syntax.
            'normalized_colon_preview' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-colon:me',
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'item' => [
                        'type' => PreviewSegment::class,
                        'options' => [
                            'route' => '/:id',
                            'defaults' => [
                                'controller' => DynamicPathController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
            // Its action matches another URL with the router.
            'normalized_rematch' => [
                'type' => Literal::class,
                'options' => [
                    'route' => '/normalized-rematch',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'rematch',
                    ],
                ],
            ],
            // One route name and http.route, matched by a Literal or by a
            // Segment, as after a deploy that changed the route's type.
            'normalized_swap' => [
                'type' => SwappableRoute::class,
                'options' => [
                    'route' => '/normalized-swap:me',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
                    ],
                ],
            ],
            // A Regex whose spec does not place one of its captures.
            'normalized_rx_unplaced' => [
                'type' => Regex::class,
                'options' => [
                    'regex' => '/normalized-rx-unplaced/(?<lang>en|fr)/about',
                    'spec' => '/normalized-rx-unplaced/en/about',
                    'defaults' => [
                        'controller' => DynamicPathController::class,
                        'action' => 'index',
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
            Controller\IndexController::class => InvokableFactory::class,
            Controller\CommonSpecsController::class => InvokableFactory::class,
            Controller\LoginController::class => LoginControllerFactory::class,
            DynamicPathController::class => InvokableFactory::class,
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
