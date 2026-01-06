<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAPI Info
    |--------------------------------------------------------------------------
    |
    | Basic information about your API that will appear in the OpenAPI spec.
    |
    */
    'info' => [
        'title' => env('OPENAPI_TITLE', 'API Documentation'),
        'version' => env('OPENAPI_VERSION', '1.0.0'),
        'description' => env('OPENAPI_DESCRIPTION', ''),
        'contact' => [
            'name' => env('OPENAPI_CONTACT_NAME', ''),
            'email' => env('OPENAPI_CONTACT_EMAIL', ''),
            'url' => env('OPENAPI_CONTACT_URL', ''),
        ],
        'license' => [
            'name' => env('OPENAPI_LICENSE_NAME', ''),
            'url' => env('OPENAPI_LICENSE_URL', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Define the servers where your API is hosted. You can define multiple
    | servers for different environments (production, staging, development).
    |
    */
    'servers' => [
        [
            'url' => env('APP_URL', 'http://localhost'),
            'description' => 'Current Environment',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Filters
    |--------------------------------------------------------------------------
    |
    | Configure which routes should be included or excluded from the
    | generated OpenAPI specification.
    |
    */
    'route_filters' => [
        // Only include routes starting with these prefixes
        'include_prefixes' => ['api/', 'v1/', 'v2/', 'v3/', 'v4/'],

        // Exclude routes starting with these prefixes
        'exclude_prefixes' => ['_ignition', 'sanctum', 'horizon', 'telescope'],

        // Exclude routes that use these middleware
        'exclude_middleware' => ['web'],

        // Only include routes that use these middleware (empty = include all)
        'include_middleware' => [],

        // Exclude routes by name pattern (regex)
        'exclude_names' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Macros
    |--------------------------------------------------------------------------
    |
    | Map custom response macros to their default HTTP status codes.
    | This helps the analyzer understand your custom response helpers.
    |
    */
    'response_macros' => [
        'show' => ['status' => 200, 'description' => 'Successful response'],
        'created' => ['status' => 201, 'description' => 'Resource created'],
        'updated' => ['status' => 200, 'description' => 'Resource updated'],
        'deleted' => ['status' => 200, 'description' => 'Resource deleted'],
        'success' => ['status' => 200, 'description' => 'Operation successful'],
        'error' => ['status' => 400, 'description' => 'Bad request'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Responses
    |--------------------------------------------------------------------------
    |
    | Define default response schemas that should be included for all
    | endpoints. These are typically error responses.
    |
    */
    'default_responses' => [
        401 => [
            'description' => 'Unauthenticated',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'Unauthenticated.'],
                        ],
                    ],
                ],
            ],
        ],
        403 => [
            'description' => 'Forbidden',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'This action is unauthorized.'],
                        ],
                    ],
                ],
            ],
        ],
        404 => [
            'description' => 'Not Found',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'Resource not found.'],
                        ],
                    ],
                ],
            ],
        ],
        422 => [
            'description' => 'Validation Error',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'The given data was invalid.'],
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        500 => [
            'description' => 'Server Error',
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'Server Error'],
                        ],
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Schemes
    |--------------------------------------------------------------------------
    |
    | Define security schemes for your API. These map middleware to
    | OpenAPI security schemes.
    |
    */
    'security_schemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'middleware' => ['auth:sanctum', 'auth:api', 'auth'],
        ],
        'apiKey' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'middleware' => ['api.key'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Configure where and how the OpenAPI specification should be generated.
    |
    */
    'output' => [
        'path' => public_path('docs/openapi.yaml'),
        'format' => 'yaml', // 'yaml' or 'json'
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for the database tables used to store OpenAPI metadata.
    |
    */
    'table_prefix' => 'openapi_',

    /*
    |--------------------------------------------------------------------------
    | Auto-Detection Settings
    |--------------------------------------------------------------------------
    |
    | Configure how the package should auto-detect information from your code.
    |
    */
    'auto_detection' => [
        // Automatically detect response schemas from JsonResource classes
        'json_resources' => true,

        // Automatically detect request body from FormRequest classes
        'form_requests' => true,

        // Automatically detect inline validation in controllers
        'inline_validation' => true,

        // Automatically detect route model binding
        'route_model_binding' => true,

        // Automatically detect pagination
        'pagination' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how tags should be automatically generated for routes.
    |
    */
    'tags' => [
        // Auto-generate tags from controller names
        'from_controller' => true,

        // Auto-generate tags from route prefixes
        'from_prefix' => false,

        // Custom tag mappings (controller => tag name)
        'mappings' => [
            // 'UserController' => 'Users',
            // 'DomainController' => 'Domains',
        ],
    ],
];
