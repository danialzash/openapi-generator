<?php

namespace Verge\OpenAPIGenerator\Builders;

use Verge\OpenAPIGenerator\Contracts\BuilderInterface;

class ParameterBuilder implements BuilderInterface
{
    protected array $config;
    protected array $parameters = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Build all parameters.
     */
    public function build(): array
    {
        return $this->parameters;
    }

    /**
     * Reset the builder.
     */
    public function reset(): self
    {
        $this->parameters = [];
        return $this;
    }

    /**
     * Add path parameters from route analysis.
     */
    public function addPathParameters(array $routeParameters): self
    {
        foreach ($routeParameters as $param) {
            $this->parameters[] = $this->buildPathParameter($param);
        }

        return $this;
    }

    /**
     * Add query parameters.
     */
    public function addQueryParameters(array $queryParams): self
    {
        foreach ($queryParams as $param) {
            $this->parameters[] = $this->buildQueryParameter($param);
        }

        return $this;
    }

    /**
     * Add header parameters.
     */
    public function addHeaderParameters(array $headerParams): self
    {
        foreach ($headerParams as $param) {
            $this->parameters[] = $this->buildHeaderParameter($param);
        }

        return $this;
    }

    /**
     * Add common pagination parameters.
     */
    public function addPaginationParameters(): self
    {
        $this->parameters[] = [
            'name' => 'page',
            'in' => 'query',
            'description' => 'Page number',
            'required' => false,
            'schema' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
        ];

        $this->parameters[] = [
            'name' => 'per_page',
            'in' => 'query',
            'description' => 'Number of items per page',
            'required' => false,
            'schema' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'default' => 15,
            ],
        ];

        return $this;
    }

    /**
     * Add common sorting parameters.
     */
    public function addSortingParameters(array $sortableFields = []): self
    {
        $this->parameters[] = [
            'name' => 'sort_by',
            'in' => 'query',
            'description' => 'Field to sort by',
            'required' => false,
            'schema' => !empty($sortableFields) 
                ? ['type' => 'string', 'enum' => $sortableFields]
                : ['type' => 'string'],
        ];

        $this->parameters[] = [
            'name' => 'order',
            'in' => 'query',
            'description' => 'Sort order',
            'required' => false,
            'schema' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'default' => 'asc',
            ],
        ];

        return $this;
    }

    /**
     * Add common search parameter.
     */
    public function addSearchParameter(): self
    {
        $this->parameters[] = [
            'name' => 'search',
            'in' => 'query',
            'description' => 'Search term',
            'required' => false,
            'schema' => ['type' => 'string'],
        ];

        return $this;
    }

    /**
     * Build a path parameter.
     */
    protected function buildPathParameter(array $param): array
    {
        $parameter = [
            'name' => $param['name'],
            'in' => 'path',
            'required' => $param['required'] ?? true,
            'schema' => $param['schema'] ?? ['type' => 'string'],
        ];

        if (isset($param['description'])) {
            $parameter['description'] = $param['description'];
        }

        if (isset($param['pattern'])) {
            $parameter['schema']['pattern'] = $param['pattern'];
        }

        if (isset($param['example'])) {
            $parameter['example'] = $param['example'];
        }

        return $parameter;
    }

    /**
     * Build a query parameter.
     */
    protected function buildQueryParameter(array $param): array
    {
        $parameter = [
            'name' => $param['name'],
            'in' => 'query',
            'required' => $param['required'] ?? false,
            'schema' => $param['schema'] ?? ['type' => 'string'],
        ];

        if (isset($param['description'])) {
            $parameter['description'] = $param['description'];
        }

        if (isset($param['example'])) {
            $parameter['example'] = $param['example'];
        }

        if (isset($param['deprecated']) && $param['deprecated']) {
            $parameter['deprecated'] = true;
        }

        // Handle array parameters
        if (isset($param['style'])) {
            $parameter['style'] = $param['style'];
        }

        if (isset($param['explode'])) {
            $parameter['explode'] = $param['explode'];
        }

        return $parameter;
    }

    /**
     * Build a header parameter.
     */
    protected function buildHeaderParameter(array $param): array
    {
        $parameter = [
            'name' => $param['name'],
            'in' => 'header',
            'required' => $param['required'] ?? false,
            'schema' => $param['schema'] ?? ['type' => 'string'],
        ];

        if (isset($param['description'])) {
            $parameter['description'] = $param['description'];
        }

        if (isset($param['example'])) {
            $parameter['example'] = $param['example'];
        }

        return $parameter;
    }

    /**
     * Merge parameters from custom definitions.
     */
    public function mergeCustomParameters(array $customParams): self
    {
        foreach ($customParams as $param) {
            // Check if parameter already exists
            $exists = false;
            foreach ($this->parameters as $existing) {
                if ($existing['name'] === $param['name'] && $existing['in'] === $param['in']) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $this->parameters[] = $param;
            }
        }

        return $this;
    }

    /**
     * Remove duplicate parameters.
     */
    public function removeDuplicates(): self
    {
        $unique = [];
        $keys = [];

        foreach ($this->parameters as $param) {
            $key = $param['name'] . ':' . $param['in'];
            if (!isset($keys[$key])) {
                $unique[] = $param;
                $keys[$key] = true;
            }
        }

        $this->parameters = $unique;
        return $this;
    }
}
