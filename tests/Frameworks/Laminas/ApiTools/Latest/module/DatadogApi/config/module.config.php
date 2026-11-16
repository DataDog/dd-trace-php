<?php
return [
    'service_manager' => [
        'factories' => [
            \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceResource::class => \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceResourceFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'datadog-api.rest.datadog-rest-service' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/datadog-rest-service[/:datadog_rest_service_id]',
                    'defaults' => [
                        'controller' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller',
                    ],
                ],
            ],
        ],
    ],
    'api-tools-versioning' => [
        'uri' => [
            0 => 'datadog-api.rest.datadog-rest-service',
        ],
    ],
    'api-tools-rest' => [
        'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
            'listener' => \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceResource::class,
            'route_name' => 'datadog-api.rest.datadog-rest-service',
            'route_identifier_name' => 'datadog_rest_service_id',
            'collection_name' => 'datadog_rest_service',
            'entity_http_methods' => [
                0 => 'GET',
                1 => 'PATCH',
                2 => 'PUT',
                3 => 'DELETE',
            ],
            'collection_http_methods' => [
                0 => 'GET',
                1 => 'POST',
            ],
            'collection_query_whitelist' => [],
            'page_size' => 25,
            'page_size_param' => null,
            'entity_class' => \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceEntity::class,
            'collection_class' => \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceCollection::class,
            'service_name' => 'DatadogRestService',
        ],
    ],
    'api-tools-content-negotiation' => [
        'controllers' => [
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => 'HalJson',
        ],
        'accept_whitelist' => [
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
                0 => 'application/vnd.datadog-api.v1+json',
                1 => 'application/hal+json',
                2 => 'application/json',
            ],
        ],
        'content_type_whitelist' => [
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
                0 => 'application/vnd.datadog-api.v1+json',
                1 => 'application/json',
            ],
        ],
    ],
    'api-tools-hal' => [
        'metadata_map' => [
            \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceEntity::class => [
                'entity_identifier_name' => 'id',
                'route_name' => 'datadog-api.rest.datadog-rest-service',
                'route_identifier_name' => 'datadog_rest_service_id',
                'hydrator' => \Laminas\Hydrator\ArraySerializableHydrator::class,
            ],
            \DatadogApi\V1\Rest\DatadogRestService\DatadogRestServiceCollection::class => [
                'entity_identifier_name' => 'id',
                'route_name' => 'datadog-api.rest.datadog-rest-service',
                'route_identifier_name' => 'datadog_rest_service_id',
                'is_collection' => true,
            ],
        ],
    ],
];
