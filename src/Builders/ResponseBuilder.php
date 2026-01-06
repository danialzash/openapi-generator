<?php

namespace Verge\OpenAPIGenerator\Builders;

use Verge\OpenAPIGenerator\Contracts\BuilderInterface;

class ResponseBuilder implements BuilderInterface
{
    protected array $config;
    protected array $responses = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build all responses.
     */
    public function build(): array
    {
        return $this->responses;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->responses = [];
        return $this;
    }

    /**
     * Add a success response.
     */
    public function addSuccessResponse(array $schema, string $description = 'Successful response', int $statusCode = 200): self
    {
        $this->responses[$statusCode] = [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => $schema,
                ],
            ],
        ];

        return $this;
    }

    /**
     * Add a created response.
     */
    public function addCreatedResponse(array $schema, string $description = 'Resource created'): self
    {
        return $this->addSuccessResponse($schema, $description, 201);
    }

    /**
     * Add a no content response.
     */
    public function addNoContentResponse(string $description = 'No content'): self
    {
        $this->responses[204] = [
            'description' => $description,
        ];

        return $this;
    }

    /**
     * Add default error responses.
     */
    public function addDefaultErrorResponses(): self
    {
        $defaultResponses = $this->config['default_responses'] ?? [];

        foreach ($defaultResponses as $statusCode => $response) {
            if (!isset($this->responses[$statusCode])) {
                $this->responses[$statusCode] = $response;
            }
        }

        return $this;
    }

    /**
     * Add a validation error response.
     */
    public function addValidationErrorResponse(): self
    {
        if (!isset($this->responses[422])) {
            $this->responses[422] = [
                'description' => 'Validation Error',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'example' => 'The given data was invalid.',
                                ],
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
            ];
        }

        return $this;
    }

    /**
     * Add an unauthorized response.
     */
    public function addUnauthorizedResponse(): self
    {
        if (!isset($this->responses[401])) {
            $this->responses[401] = [
                'description' => 'Unauthenticated',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'example' => 'Unauthenticated.',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $this;
    }

    /**
     * Add a forbidden response.
     */
    public function addForbiddenResponse(): self
    {
        if (!isset($this->responses[403])) {
            $this->responses[403] = [
                'description' => 'Forbidden',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'example' => 'This action is unauthorized.',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $this;
    }

    /**
     * Add a not found response.
     */
    public function addNotFoundResponse(): self
    {
        if (!isset($this->responses[404])) {
            $this->responses[404] = [
                'description' => 'Not Found',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'example' => 'Resource not found.',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $this;
    }

    /**
     * Add a custom response.
     */
    public function addResponse(int $statusCode, array $response): self
    {
        $this->responses[$statusCode] = $response;
        return $this;
    }

    /**
     * Merge responses from metadata.
     */
    public function mergeFromMetadata(array $responseDescriptions, array $responseExamples = []): self
    {
        foreach ($responseDescriptions as $statusCode => $description) {
            if (isset($this->responses[$statusCode])) {
                $this->responses[$statusCode]['description'] = $description;
            }
        }

        foreach ($responseExamples as $statusCode => $example) {
            if (isset($this->responses[$statusCode]['content']['application/json'])) {
                $this->responses[$statusCode]['content']['application/json']['example'] = $example;
            }
        }

        return $this;
    }

    /**
     * Build response with data envelope.
     */
    public function wrapInDataEnvelope(array $schema): array
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
    public function buildPaginatedSchema(array $itemSchema): array
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
}
