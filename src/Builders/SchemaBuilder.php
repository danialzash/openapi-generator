<?php

namespace Verge\OpenAPIGenerator\Builders;

use Verge\OpenAPIGenerator\Contracts\BuilderInterface;
use Verge\OpenAPIGenerator\Models\SchemaDefinition;

class SchemaBuilder implements BuilderInterface
{
    protected array $config;
    protected array $schemas = [];
    protected array $referencedSchemas = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build all schemas.
     */
    public function build(): array
    {
        // Merge with database schemas
        $this->mergeWithDatabaseSchemas();

        return $this->schemas;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->schemas = [];
        $this->referencedSchemas = [];
        return $this;
    }

    /**
     * Add a schema.
     */
    public function addSchema(string $name, array $schema): self
    {
        $this->schemas[$name] = $schema;
        
        // Track references
        $this->trackReferences($name, $schema);
        
        return $this;
    }

    /**
     * Add a schema from a resource analysis.
     */
    public function addFromResourceAnalysis(array $resourceAnalysis): self
    {
        $name = $resourceAnalysis['name'] ?? null;
        $schema = $resourceAnalysis['schema'] ?? null;

        if ($name && $schema) {
            $this->addSchema($name, $schema);
        }

        return $this;
    }

    /**
     * Add a schema from request analysis.
     */
    public function addFromRequestAnalysis(array $requestAnalysis, string $name = null): self
    {
        $schema = $requestAnalysis['schema'] ?? null;
        
        if ($schema) {
            $schemaName = $name ?? $this->generateSchemaName($requestAnalysis['class'] ?? 'Request');
            $this->addSchema($schemaName, $schema);
        }

        return $this;
    }

    /**
     * Get a schema by name.
     */
    public function getSchema(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }

    /**
     * Check if a schema exists.
     */
    public function hasSchema(string $name): bool
    {
        return isset($this->schemas[$name]);
    }

    /**
     * Get a reference to a schema.
     */
    public function getRef(string $name): array
    {
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * Build a request body schema.
     */
    public function buildRequestBody(array $schema, string $description = null, bool $required = true): array
    {
        $body = [
            'required' => $required,
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ];

        if ($description) {
            $body['description'] = $description;
        }

        return $body;
    }

    /**
     * Build a response schema.
     */
    public function buildResponse(array $schema, string $description = 'Successful response', int $statusCode = 200): array
    {
        return [
            $statusCode => [
                'description' => $description,
                'content' => [
                    'application/json' => [
                        'schema' => $this->wrapInDataEnvelope($schema),
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a paginated response schema.
     */
    public function buildPaginatedResponse(array $itemSchema, string $description = 'Paginated response'): array
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
                        'path' => ['type' => 'string', 'format' => 'uri'],
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
     * Build an error response schema.
     */
    public function buildErrorResponse(int $statusCode, string $description = 'Error response'): array
    {
        return [
            $statusCode => [
                'description' => $description,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => ['type' => 'string'],
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
        ];
    }

    /**
     * Wrap schema in data envelope.
     */
    protected function wrapInDataEnvelope(array $schema): array
    {
        // If it's already a collection response with data property, return as is
        if (isset($schema['properties']['data'])) {
            return $schema;
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => $schema,
                'message' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Generate a schema name from class name.
     */
    protected function generateSchemaName(string $className): string
    {
        $name = class_basename($className);
        
        // Remove common suffixes
        $name = preg_replace('/(Request|Resource|Collection)$/', '', $name);
        
        return $name ?: 'Schema';
    }

    /**
     * Track schema references.
     */
    protected function trackReferences(string $name, array $schema): void
    {
        $refs = $this->findReferences($schema);
        
        foreach ($refs as $ref) {
            if (!isset($this->referencedSchemas[$ref])) {
                $this->referencedSchemas[$ref] = [];
            }
            $this->referencedSchemas[$ref][] = $name;
        }
    }

    /**
     * Find all $ref references in a schema.
     */
    protected function findReferences(array $schema): array
    {
        $refs = [];

        array_walk_recursive($schema, function ($value, $key) use (&$refs) {
            if ($key === '$ref' && is_string($value)) {
                // Extract schema name from reference
                if (preg_match('/#\/components\/schemas\/(.+)$/', $value, $matches)) {
                    $refs[] = $matches[1];
                }
            }
        });

        return array_unique($refs);
    }

    /**
     * Merge with database schemas.
     */
    protected function mergeWithDatabaseSchemas(): void
    {
        try {
            $dbSchemas = SchemaDefinition::all();
            
            foreach ($dbSchemas as $dbSchema) {
                $name = $dbSchema->name;
                
                // Database schemas have lower priority - only add if not exists
                if (!isset($this->schemas[$name])) {
                    $this->schemas[$name] = $dbSchema->toOpenAPISchema();
                } else {
                    // Merge additional info from database (description, example)
                    if ($dbSchema->description && !isset($this->schemas[$name]['description'])) {
                        $this->schemas[$name]['description'] = $dbSchema->description;
                    }
                    if ($dbSchema->example && !isset($this->schemas[$name]['example'])) {
                        $this->schemas[$name]['example'] = $dbSchema->example;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Database not available, continue without
        }
    }

    /**
     * Get all missing referenced schemas.
     */
    public function getMissingSchemas(): array
    {
        $missing = [];

        foreach ($this->referencedSchemas as $ref => $usedBy) {
            if (!isset($this->schemas[$ref])) {
                $missing[$ref] = $usedBy;
            }
        }

        return $missing;
    }

    /**
     * Add placeholder schemas for missing references.
     */
    public function addPlaceholdersForMissing(): self
    {
        foreach ($this->getMissingSchemas() as $name => $usedBy) {
            $this->schemas[$name] = [
                'type' => 'object',
                'description' => "Auto-generated placeholder for {$name}",
            ];
        }

        return $this;
    }
}
