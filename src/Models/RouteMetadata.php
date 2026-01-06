<?php

namespace Verge\OpenAPIGenerator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class RouteMetadata extends Model
{
    /**
     * Get the table name with prefix.
     */
    public function getTable(): string
    {
        $prefix = config('openapi-generator.table_prefix', 'openapi_');
        return $prefix . 'routes';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'route_name',
        'http_method',
        'uri',
        'controller',
        'action',
        'operation_id',
        'summary',
        'description',
        'tags',
        'deprecated',
        'external_docs_url',
        'external_docs_description',
        'request_body_description',
        'request_body_example',
        'request_body_required',
        'response_descriptions',
        'response_examples',
        'custom_parameters',
        'security_requirements',
        'is_hidden',
        'auto_detected_data',
        'last_scanned_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tags' => 'array',
        'deprecated' => 'boolean',
        'request_body_example' => 'array',
        'request_body_required' => 'boolean',
        'response_descriptions' => 'array',
        'response_examples' => 'array',
        'custom_parameters' => 'array',
        'security_requirements' => 'array',
        'is_hidden' => 'boolean',
        'auto_detected_data' => 'array',
        'last_scanned_at' => 'datetime',
    ];

    /**
     * Scope a query to only include visible routes.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * Scope a query to filter by HTTP method.
     */
    public function scopeMethod(Builder $query, string $method): Builder
    {
        return $query->where('http_method', strtoupper($method));
    }

    /**
     * Scope a query to filter by URI prefix.
     */
    public function scopeUriPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('uri', 'like', $prefix . '%');
    }

    /**
     * Scope a query to filter by tags.
     */
    public function scopeWithTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Get the operation ID, generating one if not set.
     */
    public function getOperationIdAttribute(?string $value): string
    {
        if ($value) {
            return $value;
        }

        // Generate operation ID from method and URI
        $uri = str_replace(['{', '}', '/', '-'], ['', '', '_', '_'], $this->uri);
        $uri = preg_replace('/[^a-zA-Z0-9_]/', '', $uri);
        $uri = preg_replace('/_+/', '_', $uri);
        $uri = trim($uri, '_');

        return strtolower($this->http_method) . ucfirst(lcfirst(
            str_replace('_', '', ucwords($uri, '_'))
        ));
    }

    /**
     * Find a route by method and URI.
     */
    public static function findByRoute(string $method, string $uri): ?self
    {
        return static::where('http_method', strtoupper($method))
            ->where('uri', $uri)
            ->first();
    }

    /**
     * Create or update a route from scan data.
     */
    public static function updateOrCreateFromScan(array $scanData): self
    {
        return static::updateOrCreate(
            [
                'http_method' => strtoupper($scanData['method']),
                'uri' => $scanData['uri'],
            ],
            [
                'route_name' => $scanData['name'] ?? null,
                'controller' => $scanData['controller'] ?? null,
                'action' => $scanData['action'] ?? null,
                'auto_detected_data' => $scanData['auto_detected'] ?? null,
                'last_scanned_at' => now(),
            ]
        );
    }

    /**
     * Get the full OpenAPI operation object.
     */
    public function toOpenAPIOperation(): array
    {
        $operation = [];

        if ($this->operation_id) {
            $operation['operationId'] = $this->operation_id;
        }

        if ($this->summary) {
            $operation['summary'] = $this->summary;
        }

        if ($this->description) {
            $operation['description'] = $this->description;
        }

        if ($this->tags && count($this->tags) > 0) {
            $operation['tags'] = $this->tags;
        }

        if ($this->deprecated) {
            $operation['deprecated'] = true;
        }

        if ($this->external_docs_url) {
            $operation['externalDocs'] = [
                'url' => $this->external_docs_url,
            ];
            if ($this->external_docs_description) {
                $operation['externalDocs']['description'] = $this->external_docs_description;
            }
        }

        if ($this->security_requirements !== null) {
            $operation['security'] = $this->security_requirements;
        }

        return $operation;
    }
}
