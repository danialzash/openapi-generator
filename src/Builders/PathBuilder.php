<?php

namespace Verge\OpenAPIGenerator\Builders;

use Verge\OpenAPIGenerator\Contracts\BuilderInterface;
use Verge\OpenAPIGenerator\Models\RouteMetadata;

class PathBuilder implements BuilderInterface
{
    protected array $config;
    protected SchemaBuilder $schemaBuilder;
    protected array $paths = [];

    public function __construct(array $config, SchemaBuilder $schemaBuilder)
    {
        $this->config = $config;
        $this->schemaBuilder = $schemaBuilder;
    }

    /**
     * Build all paths.
     */
    public function build(): array
    {
        return $this->paths;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->paths = [];
        return $this;
    }

    /**
     * Add a path from analyzed route data.
     */
    public function addPath(array $routeData, array $analysisData = []): self
    {
        $uri = $this->normalizeUri($routeData['uri']);
        $method = strtolower($routeData['method']);

        if (!isset($this->paths[$uri])) {
            $this->paths[$uri] = [];
        }

        $operation = $this->buildOperation($routeData, $analysisData);
        $this->paths[$uri][$method] = $operation;

        return $this;
    }

    /**
     * Build an operation object.
     */
    protected function buildOperation(array $routeData, array $analysisData): array
    {
        $operation = [];

        // Get metadata from database
        $metadata = $this->getRouteMetadata($routeData);

        // Operation ID
        $operationId = $metadata?->operation_id ?? $this->generateOperationId($routeData);
        $operation['operationId'] = $operationId;

        // Summary and description
        if ($metadata?->summary) {
            $operation['summary'] = $metadata->summary;
        } else {
            $operation['summary'] = $this->generateSummary($routeData);
        }

        if ($metadata?->description) {
            $operation['description'] = $metadata->description;
        }

        // Tags
        $tags = $metadata?->tags ?? $this->generateTags($routeData);
        if (!empty($tags)) {
            $operation['tags'] = $tags;
        }

        // Deprecated
        if ($metadata?->deprecated) {
            $operation['deprecated'] = true;
        }

        // External docs
        if ($metadata?->external_docs_url) {
            $operation['externalDocs'] = [
                'url' => $metadata->external_docs_url,
            ];
            if ($metadata->external_docs_description) {
                $operation['externalDocs']['description'] = $metadata->external_docs_description;
            }
        }

        // Parameters
        $parameters = $this->buildParameters($routeData, $analysisData, $metadata);
        if (!empty($parameters)) {
            $operation['parameters'] = $parameters;
        }

        // Request body
        $requestBody = $this->buildRequestBody($routeData, $analysisData, $metadata);
        if ($requestBody) {
            $operation['requestBody'] = $requestBody;
        }

        // Responses
        $operation['responses'] = $this->buildResponses($routeData, $analysisData, $metadata);

        // Security
        $security = $this->buildSecurity($analysisData, $metadata);
        if ($security !== null) {
            $operation['security'] = $security;
        }

        return $operation;
    }

    /**
     * Get route metadata from database.
     */
    protected function getRouteMetadata(array $routeData): ?RouteMetadata
    {
        try {
            return RouteMetadata::findByRoute($routeData['method'], $routeData['uri']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Normalize URI for OpenAPI.
     */
    protected function normalizeUri(string $uri): string
    {
        // Ensure leading slash
        $uri = '/' . ltrim($uri, '/');

        // Convert Laravel route parameters to OpenAPI format
        // {param} stays as {param}
        // {param?} becomes {param} (optional handled via required: false)
        $uri = preg_replace('/\{(\w+)\?\}/', '{$1}', $uri);

        return $uri;
    }

    /**
     * Generate operation ID.
     */
    protected function generateOperationId(array $routeData): string
    {
        // Use route name if available
        if (!empty($routeData['name'])) {
            return str_replace('.', '_', $routeData['name']);
        }

        // Generate from controller and action
        $controller = class_basename($routeData['controller'] ?? 'Unknown');
        $controller = str_replace('Controller', '', $controller);
        $action = $routeData['action'] ?? 'index';

        return lcfirst($controller) . ucfirst($action);
    }

    /**
     * Generate summary from route data.
     */
    protected function generateSummary(array $routeData): string
    {
        $action = $routeData['action'] ?? 'unknown';
        $controller = class_basename($routeData['controller'] ?? 'Resource');
        $resource = str_replace('Controller', '', $controller);

        return match ($action) {
            'index' => "List {$resource}s",
            'show' => "Get {$resource}",
            'store', 'create' => "Create {$resource}",
            'update' => "Update {$resource}",
            'destroy', 'delete' => "Delete {$resource}",
            default => ucfirst($action) . ' ' . $resource,
        };
    }

    /**
     * Generate tags from route data.
     */
    protected function generateTags(array $routeData): array
    {
        if (!($this->config['tags']['from_controller'] ?? true)) {
            return [];
        }

        $controller = $routeData['controller'] ?? null;
        if (!$controller) {
            return [];
        }

        $className = class_basename($controller);
        
        // Check mappings
        $mappings = $this->config['tags']['mappings'] ?? [];
        if (isset($mappings[$className])) {
            return [$mappings[$className]];
        }

        // Generate from controller name
        $tag = str_replace('Controller', '', $className);
        
        return [$tag];
    }

    /**
     * Build parameters.
     */
    protected function buildParameters(array $routeData, array $analysisData, ?RouteMetadata $metadata): array
    {
        $parameters = [];

        // Add path parameters
        foreach ($routeData['parameters'] ?? [] as $param) {
            $parameters[] = [
                'name' => $param['name'],
                'in' => 'path',
                'required' => $param['required'] ?? true,
                'schema' => $param['schema'] ?? ['type' => 'string'],
                'description' => $param['description'] ?? "The {$param['name']} identifier",
            ];
        }

        // Add query parameters from request analysis
        $requestAnalysis = $analysisData['request'] ?? [];
        foreach ($requestAnalysis['query_parameters'] ?? [] as $param) {
            $parameters[] = $param;
        }

        // Add custom parameters from metadata
        if ($metadata?->custom_parameters) {
            foreach ($metadata->custom_parameters as $param) {
                // Check if parameter already exists
                $exists = false;
                foreach ($parameters as $existing) {
                    if ($existing['name'] === $param['name'] && $existing['in'] === $param['in']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $parameters[] = $param;
                }
            }
        }

        return $parameters;
    }

    /**
     * Build request body.
     */
    protected function buildRequestBody(array $routeData, array $analysisData, ?RouteMetadata $metadata): ?array
    {
        $method = strtolower($routeData['method']);

        // Only POST, PUT, PATCH typically have request bodies
        if (!in_array($method, ['post', 'put', 'patch'])) {
            return null;
        }

        $requestAnalysis = $analysisData['request'] ?? [];
        $controllerAnalysis = $analysisData['controller'] ?? [];

        // Get schema from request analysis
        $schema = $requestAnalysis['schema'] ?? null;

        // Or from inline validation
        if (!$schema && !empty($controllerAnalysis['inline_validation'])) {
            $firstValidation = $controllerAnalysis['inline_validation'][0] ?? null;
            $schema = $firstValidation['schema'] ?? null;
        }

        if (!$schema) {
            // Return empty schema for write operations
            $schema = ['type' => 'object'];
        }

        $requestBody = [
            'required' => $metadata?->request_body_required ?? true,
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ];

        // Add description
        if ($metadata?->request_body_description) {
            $requestBody['description'] = $metadata->request_body_description;
        }

        // Add example
        if ($metadata?->request_body_example) {
            $requestBody['content']['application/json']['example'] = $metadata->request_body_example;
        }

        return $requestBody;
    }

    /**
     * Build responses.
     */
    protected function buildResponses(array $routeData, array $analysisData, ?RouteMetadata $metadata): array
    {
        $responses = [];
        $method = strtolower($routeData['method']);
        $action = $routeData['action'] ?? '';

        // Determine success response code
        $successCode = match (true) {
            $method === 'post' && in_array($action, ['store', 'create']) => 201,
            $method === 'delete' && in_array($action, ['destroy', 'delete']) => 200,
            default => 200,
        };

        // Build success response
        $responseSchema = $this->buildResponseSchema($routeData, $analysisData);
        $responses[$successCode] = [
            'description' => $this->getSuccessDescription($successCode, $action),
            'content' => [
                'application/json' => [
                    'schema' => $responseSchema,
                ],
            ],
        ];

        // Merge response descriptions from metadata
        if ($metadata?->response_descriptions) {
            foreach ($metadata->response_descriptions as $code => $description) {
                if (isset($responses[$code])) {
                    $responses[$code]['description'] = $description;
                }
            }
        }

        // Merge response examples from metadata
        if ($metadata?->response_examples) {
            foreach ($metadata->response_examples as $code => $example) {
                if (isset($responses[$code]['content']['application/json'])) {
                    $responses[$code]['content']['application/json']['example'] = $example;
                }
            }
        }

        // Add default error responses
        $defaultResponses = $this->config['default_responses'] ?? [];
        foreach ($defaultResponses as $code => $response) {
            if (!isset($responses[$code])) {
                $responses[$code] = $response;
            }
        }

        return $responses;
    }

    /**
     * Build response schema.
     */
    protected function buildResponseSchema(array $routeData, array $analysisData): array
    {
        $responseAnalysis = $analysisData['response'] ?? [];
        $schemas = $responseAnalysis['schemas'] ?? [];

        // If we have analyzed response schemas, use them
        if (!empty($schemas)) {
            $firstSchema = array_values($schemas)[0] ?? null;
            if ($firstSchema && isset($firstSchema['schema'])) {
                // Add to schema builder
                $this->schemaBuilder->addSchema($firstSchema['name'], $firstSchema['schema']);
                
                // Check if it's a collection
                if ($firstSchema['is_collection'] ?? false) {
                    return $this->buildPaginatedResponseSchema(
                        $this->schemaBuilder->getRef($firstSchema['name'])
                    );
                }

                return $this->wrapInDataEnvelope(
                    $this->schemaBuilder->getRef($firstSchema['name'])
                );
            }
        }

        // Default response schema
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'object'],
                'message' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Wrap schema in data envelope.
     */
    protected function wrapInDataEnvelope(array $schema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => $schema,
                'message' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Build paginated response schema.
     */
    protected function buildPaginatedResponseSchema(array $itemSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => $itemSchema,
                ],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        'last' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => ['type' => 'integer'],
                        'from' => ['type' => 'integer', 'nullable' => true],
                        'last_page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'to' => ['type' => 'integer', 'nullable' => true],
                        'total' => ['type' => 'integer'],
                    ],
                ],
                'message' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Get success description.
     */
    protected function getSuccessDescription(int $code, string $action): string
    {
        return match ($code) {
            201 => 'Resource created successfully',
            204 => 'No content',
            default => match ($action) {
                'index' => 'List retrieved successfully',
                'show' => 'Resource retrieved successfully',
                'update' => 'Resource updated successfully',
                'destroy', 'delete' => 'Resource deleted successfully',
                default => 'Successful response',
            },
        };
    }

    /**
     * Build security requirements.
     */
    protected function buildSecurity(array $analysisData, ?RouteMetadata $metadata): ?array
    {
        // Use metadata override if set
        if ($metadata?->security_requirements !== null) {
            return $metadata->security_requirements;
        }

        // Get from middleware analysis
        $middlewareAnalysis = $analysisData['middleware'] ?? [];
        $security = $middlewareAnalysis['security'] ?? [];

        if (empty($security)) {
            return null; // Use global security
        }

        $requirements = [];
        foreach ($security as $sec) {
            $requirements[] = [
                $sec['scheme'] => $sec['scopes'] ?? [],
            ];
        }

        return $requirements;
    }
}
